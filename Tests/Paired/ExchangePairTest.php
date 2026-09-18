<?php

declare(strict_types=1);

namespace Lizard\Typo3ToTypo3\Tests\Paired;

use PHPUnit\Framework\TestCase;

/** Runs on the host and controls only the two existing isolated Testing contexts. */
final class ExchangePairTest extends TestCase
{
    private function fixture(int $version, array $input): array
    {
        $process = proc_open(['ddev', 'exec', 'env', 'TYPO3_CONTEXT=Testing', 'TYPO3_PATH_APP=/var/www/html/var/exchange-testing',
            'TYPO3_PATH_ROOT=/var/www/html/public', 'php', '/opt/typo3-to-typo3/Tests/Paired/fixture.php'],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes, dirname(__DIR__, 2) . '/dev/typo3-v' . $version);
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
            foreach (array_reverse($started) as $version) { $this->fixture($version, ['operation' => 'cleanup']); }
        }
    }
}
