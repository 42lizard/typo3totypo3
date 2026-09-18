<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Paired;

use PHPUnit\Framework\TestCase;

/** Runs on the host and controls isolated Testing contexts in the two existing DDEV projects. */
final class ExchangePairTest extends TestCase
{
    private function fixture(int|string $version, array $input): array
    {
        $context = $version === 'c' ? 'Testing/PeerC' : 'Testing';
        $app = $version === 'c' ? 'exchange-testing-c' : 'exchange-testing';
        $project = $version === 'c' ? 14 : $version;
        $process = proc_open(['ddev', 'exec', 'env', 'TYPO3_CONTEXT=' . $context, 'TYPO3_PATH_APP=/var/www/html/var/' . $app,
            'TYPO3_PATH_ROOT=/var/www/html/public', 'php', '/opt/typo3-to-typo3/Tests/Paired/fixture.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__, 2) . '/dev/typo3-v' . $project);
        if (!is_resource($process)) { throw new \RuntimeException('Cannot start DDEV fixture.'); }
        fwrite($pipes[0], json_encode($input, JSON_THROW_ON_ERROR));
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error . $output);
        return json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    }

    public function testThreePeersRequireDirectSiteAndEnvironmentGrants(): void
    {
        $peers = ['a' => 13, 'b' => 14, 'c' => 'c'];
        $origins = ['a' => 'https://t3exchange-v13-testing.ddev.site', 'b' => 'https://t3exchange-v14-testing.ddev.site', 'c' => 'https://t3exchange-v14-testing-c.ddev.site'];
        $configs = [];
        foreach (array_keys($peers) as $i => $name) {
            $configs[$name] = ['enabled' => true, 'instance' => sprintf('40000000-0000-4000-8000-%012d', $i), 'incoming' => [], 'outgoing' => [],
                'exchange' => ['environment' => sprintf('50000000-0000-4000-8000-%012d', $i), 'incoming' => [], 'outgoing' => []]];
        }
        $tokens = $usageTokens = $generations = [];
        foreach (['ab', 'ba', 'bc', 'cb'] as $i => $pair) {
            [$from, $to] = str_split($pair);
            $tokens[$pair] = bin2hex(random_bytes(32));
            $usageTokens[$pair] = bin2hex(random_bytes(32));
            $generations[$pair] = sprintf('60000000-0000-4000-8000-%012d', $i);
            $configs[$to]['incoming'][$configs[$from]['instance']] = ['enabled' => true, 'tokenHash' => hash('sha256', $tokens[$pair]),
                'sites' => $pair === 'cb' ? [] : ['main'], 'requestsPerMinute' => 120];
            $configs[$to]['exchange']['incoming'][$pair] = ['enabled' => true, 'instance' => $configs[$from]['instance'],
                'environment' => $configs[$from]['exchange']['environment'], 'capability' => 'usage', 'generation' => $generations[$pair],
                'tokenHash' => hash('sha256', $usageTokens[$pair]), 'sites' => ['main']];
        }
        $started = [];
        try {
            $references = [];
            $fingerprints = [];
            foreach ($peers as $name => $version) {
                $ready = $this->fixture($version, ['operation' => 'ready']);
                self::assertSame($version === 'c' ? 'db_testing_c' : 'db_testing', $ready['database']);
                self::assertSame($version === 'c' ? 'Testing/PeerC' : 'Testing', $ready['context']);
                $fingerprints[] = $ready['keyFingerprint'];
                $started[] = $version;
                $references[$name] = $this->fixture($version, ['operation' => 'setup', 'config' => $configs[$name]]);
            }
            self::assertCount(3, array_unique($fingerprints), 'Each Testing context must have an independent encryption key.');
            $resolve = function (string $from, string $to, string $token, ?string $claimedPeer = null) use ($peers, $origins, $configs, $references): array {
                return $this->fixture($peers[$from], ['operation' => 'request', 'origin' => $origins[$to], 'path' => '/typo3-exchange/v1/resolve',
                    'headers' => ['Authorization' => 'Bearer ' . $token, 'X-TYPO3-Peer' => $configs[$claimedPeer ?? $from]['instance']],
                    'payload' => ['references' => [$references[$to]]]]);
            };
            foreach (['ab', 'ba', 'bc'] as $pair) {
                $response = $resolve($pair[0], $pair[1], $tokens[$pair]);
                self::assertSame(200, $response['status']);
                self::assertSame('resolved', $response['body']['results'][0]['status']);
                self::assertSame($origins[$pair[1]] . '/paired-exchange', $response['body']['results'][0]['url']);
                self::assertSame($configs[$pair[1]]['exchange']['environment'], $response['body']['environment']);
            }
            // A knows B, and B knows C: neither direction acquires transitive trust.
            self::assertSame(401, $resolve('a', 'c', $tokens['ab'])['status']);
            self::assertSame(401, $resolve('c', 'a', $tokens['cb'])['status']);
            // A's token cannot impersonate B on C, nor can C impersonate B on A.
            self::assertSame(401, $resolve('a', 'c', $tokens['ab'], 'b')['status']);
            self::assertSame(401, $resolve('c', 'a', $tokens['cb'], 'b')['status']);
            self::assertSame(401, $resolve('c', 'b', $tokens['cb'], 'a')['status']);
            $denied = $resolve('c', 'b', $tokens['cb']);
            self::assertSame(403, $denied['status']);
            self::assertArrayNotHasKey('results', $denied['body']);

            $capabilities = function (string $pair, array $override = []) use ($peers, $origins, $configs, $usageTokens, $generations): array {
                return $this->fixture($peers[$pair[0]], ['operation' => 'request', 'origin' => $origins[$pair[1]], 'path' => '/typo3-exchange/v2/capabilities',
                    'headers' => $override + ['Authorization' => 'Bearer ' . $usageTokens[$pair], 'X-TYPO3-Peer' => $configs[$pair[0]]['instance'],
                        'X-TYPO3-Environment' => $configs[$pair[0]]['exchange']['environment'], 'X-TYPO3-Generation' => $generations[$pair], 'X-TYPO3-Capability' => 'usage'],
                    'payload' => ['protocol' => 2]]);
            };
            foreach (['ab', 'ba', 'bc', 'cb'] as $pair) {
                $response = $capabilities($pair);
                self::assertSame(200, $response['status']);
                self::assertSame($configs[$pair[1]]['instance'], $response['body']['instance']);
                self::assertSame($configs[$pair[1]]['exchange']['environment'], $response['body']['environment']);
            }
            foreach ([
                ['X-TYPO3-Peer' => $configs['c']['instance']],
                ['X-TYPO3-Environment' => $configs['c']['exchange']['environment']],
                ['X-TYPO3-Generation' => $generations['cb']],
                ['X-TYPO3-Capability' => 'notify'],
                ['Authorization' => 'Bearer ' . $tokens['ab']],
                ['Authorization' => 'Bearer ' . $usageTokens['cb']],
            ] as $override) {
                self::assertSame(401, $capabilities('ab', $override)['status']);
            }
            // Revoking A on B does not revoke the independently configured C channel.
            $configs['b']['incoming'][$configs['a']['instance']]['enabled'] = false;
            $configs['b']['exchange']['incoming']['ab']['enabled'] = false;
            $this->fixture(14, ['operation' => 'configure', 'config' => $configs['b']]);
            self::assertSame(403, $resolve('a', 'b', $tokens['ab'])['status']);
            self::assertSame(403, $capabilities('ab')['status']);
            self::assertSame(200, $capabilities('cb')['status']);
        } finally {
            $errors = [];
            foreach (array_reverse($started) as $version) {
                try { $this->fixture($version, ['operation' => 'cleanup']); }
                catch (\Throwable $error) { $errors[] = $error; }
            }
            if ($errors) { throw $errors[0]; }
        }
    }

    public function testUsageAndRefreshCrossBothTypo3MajorVersions(): void
    {
        // An exact committed archive makes rollback reproducible without switching the checkout.
        $root = dirname(__DIR__, 2);
        foreach ([13, 14] as $version) {
            $directory = $root . '/dev/typo3-v' . $version . '/var/exchange-testing/rollback-extension';
            if (!is_dir($directory)) { mkdir($directory, 0777, true); }
            $archive = $directory . '/baseline.tar';
            $process = proc_open(['git', 'archive', '--format=tar', '--output=' . $archive, '65bed0f361a1e7ac6c468c858783204b5ce5e7d8'], [], $pipes, $root);
            self::assertIsResource($process);
            self::assertSame(0, proc_close($process));
            (new \PharData($archive))->extractTo($directory, overwrite: true);
            unlink($archive);
        }
        $identity = static fn(int $v): string => sprintf('10000000-0000-4000-8000-%012d', $v);
        $environment = static fn(int $v): string => sprintf('20000000-0000-4000-8000-%012d', $v);
        $generation = static fn(int $v, int $kind): string => sprintf('30000000-0000-4000-8000-%012d', $v * 10 + $kind);
        $tokens = [13 => bin2hex(random_bytes(32)), 14 => bin2hex(random_bytes(32))];
        $origin = static fn(int $v): string => 'https://t3exchange-v' . $v . '-testing.ddev.site';
        $started = [];
        try {
            $references = [];
            foreach ([13, 14] as $version) {
                $other = $version === 13 ? 14 : 13;
                $remote = ['enabled' => true, 'instance' => $identity($other), 'environment' => $environment($other), 'sites' => ['main']];
                $out = $remote + ['token' => $tokens[$version], 'endpoint' => $origin($other) . '/typo3-exchange/v2'];
                $in = $remote + ['tokenHash' => hash('sha256', $tokens[$other])];
                $config = ['enabled' => true, 'instance' => $identity($version),
                    'incoming' => [$identity($other) => ['enabled' => true, 'sites' => ['main'], 'tokenHash' => hash('sha256', $tokens[$other]), 'requestsPerMinute' => 120]],
                    'outgoing' => ['peer' => ['enabled' => true, 'instance' => $identity($other), 'environment' => $environment($other), 'token' => $tokens[$version], 'endpoint' => $origin($other) . '/typo3-exchange/v1/resolve', 'origins' => [$origin($other)]]],
                    'exchange' => ['environment' => $environment($version),
                        'incoming' => ['usage' => $in + ['capability' => 'usage', 'generation' => $generation($other, 1)], 'notify' => $in + ['capability' => 'notify', 'generation' => $generation($other, 2)]],
                        'outgoing' => ['usage' => $out + ['capability' => 'usage', 'generation' => $generation($version, 1)], 'notify' => $out + ['capability' => 'notify', 'generation' => $generation($version, 2)]]]];
                $this->fixture($version, ['operation' => 'ready']);
                $started[] = $version;
                $references[$version] = $this->fixture($version, ['operation' => 'setup', 'config' => $config]);
            }
            foreach ([13, 14] as $version) {
                $other = $version === 13 ? 14 : 13;
                $this->fixture($version, ['operation' => 'link', 'reference' => $references[$other], 'url' => $origin($other) . '/paired-exchange']);
            }
            foreach ([13, 14, 13, 14] as $version) { self::assertSame(0, $this->fixture($version, ['operation' => 'sync'])['failed']); }
            foreach ([13, 14] as $version) { self::assertSame(1, $this->fixture($version, ['operation' => 'usage'])['present']); }
            foreach ([13, 14] as $version) {
                $other = $version === 13 ? 14 : 13;
                $rollback = $this->fixture($version, ['operation' => 'rollback', 'reference' => $references[$other],
                    'ownReference' => $references[$version], 'caller' => $identity($other), 'token' => $tokens[$other], 'origin' => $origin($version)]);
                self::assertStringContainsString('/rollback-extension/Classes/', $rollback['source']);
                self::assertTrue($rollback['preserved']);
                self::assertSame(0, $rollback['failed']);
                self::assertSame($origin($other) . '/paired-exchange', $rollback['url']);
                self::assertSame(200, $rollback['legacyStatus']);
                $legacy = $this->fixture($other, ['operation' => 'consume-legacy', 'reference' => $references[$version], 'response' => $rollback['legacyResponse']]);
                self::assertTrue($legacy['boundRejected']);
                self::assertSame($origin($version) . '/paired-exchange', $legacy['url']);
            }
            $this->fixture(14, ['operation' => 'change', 'fields' => ['slug' => '/paired-renamed']]);
            self::assertSame(0, $this->fixture(14, ['operation' => 'sync'])['failed']);
            self::assertSame(['status' => 'resolved', 'url' => $origin(14) . '/paired-renamed'], $this->fixture(13, ['operation' => 'refresh', 'reference' => $references[14]]));
            $this->fixture(13, ['operation' => 'change', 'fields' => ['hidden' => 1]]);
            self::assertSame(0, $this->fixture(13, ['operation' => 'sync'])['failed']);
            self::assertSame('unavailable', $this->fixture(14, ['operation' => 'refresh', 'reference' => $references[13]])['status']);
            self::assertSame(1, $this->fixture(13, ['operation' => 'usage'])['present']);
            $this->fixture(13, ['operation' => 'remove']);
            self::assertSame(0, $this->fixture(13, ['operation' => 'sync'])['failed']);
            self::assertSame(0, $this->fixture(14, ['operation' => 'usage'])['present']);
        } finally {
            $errors = [];
            foreach (array_reverse($started) as $version) {
                try { $this->fixture($version, ['operation' => 'cleanup']); }
                catch (\Throwable $error) { $errors[] = $error; }
            }
            if ($errors) { throw $errors[0]; }
        }
    }
}
