#!/usr/bin/env python3
"""Loopback-only HTTPS peer for the save-budget test; never fetches submitted URLs."""
import json
import os
from pathlib import Path
import ssl
import sys
import time
import uuid
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib.parse import urlsplit

if os.environ.get('TYPO3_CONTEXT') != 'Testing' or os.environ.get('TYPO3_PATH_APP') != '/var/www/html/var/exchange-testing':
    raise SystemExit('Requires the isolated DDEV Testing context.')
config = json.loads(sys.stdin.readline())


class Resolver(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass  # Do not log authorization headers or submitted URLs.

    def do_POST(self):
        peer = config['tokens'].get(self.headers.get('Authorization', '').removeprefix('Bearer '))
        if self.path != '/typo3-exchange/v1/resolve' or not peer or self.headers.get('X-TYPO3-Peer') != config['caller']:
            self.send_error(403)
            return
        size = int(self.headers.get('Content-Length', '0'))
        if not 0 < size <= 65536:
            self.send_error(413)
            return
        urls = json.loads(self.rfile.read(size))['urls']
        if not 0 < len(urls) <= 50 or any(urlsplit(url).netloc != urlsplit(peer['origin']).netloc for url in urls):
            self.send_error(400)
            return
        delay = float(Path(config['delayFile']).read_text())
        if delay < 0:
            self.send_response(302)
            self.send_header('Location', '/forbidden')
            self.send_header('Content-Length', '0')
            self.end_headers()
            return
        time.sleep(delay)
        body = json.dumps({'protocol': 1, 'instance': peer['instance'], 'results': [
            {'status': 'resolved', 'url': url, 'reference': {
                'instance': peer['instance'], 'page': str(uuid.UUID(bytes=uuid.uuid5(uuid.NAMESPACE_URL, url).bytes, version=4)), 'language': 0,
            }} for url in urls
        ]}).encode()
        try:
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.send_header('Content-Length', str(len(body)))
            self.end_headers()
            self.wfile.write(body)
        except (BrokenPipeError, ConnectionResetError, ssl.SSLError):
            pass  # Expected when the real client exhausts its save deadline.


server = ThreadingHTTPServer(('127.0.0.1', 0), Resolver)
tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
tls.load_cert_chain('/etc/ssl/certs/master.crt', '/etc/ssl/certs/master.key')
server.socket = tls.wrap_socket(server.socket, server_side=True)
print(server.server_port, flush=True)
server.serve_forever()
