#!/usr/bin/env python3
"""Exercise the production Traefik router rules with isolated test origins."""
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parents[1]
routers = {}
for name, field, value in re.findall(r'- traefik\.http\.routers\.([\w-]+)\.([\w.]+)=(.+)', (root / 'docker-compose.prod.yml').read_text()):
    if name not in {'account', 'admin', 'account-api', 'admin-api', 'myapp'}:
        continue
    router = routers.setdefault(name, {})
    if field == 'rule':
        router['rule'] = value.replace('.vashfindir.ru', '.auth.test')
    elif field == 'priority':
        router['priority'] = int(value)
    elif field == 'service':
        router['service'] = value
    router['entryPoints'] = ['https']
    router['tls'] = {}
routers['myapp']['service'] = 'myapp'
services = {name: {'loadBalancer': {'servers': [{'url': url}]}} for name, url in {
    'account': 'http://account-image', 'admin': 'http://admin-image', 'myapp': 'http://site',
}.items()}
(root / '.frontend-auth/routes.json').write_text(json.dumps({
    'http': {'routers': routers, 'services': services},
    'tls': {'certificates': [{'certFile': '/certs/server.crt', 'keyFile': '/certs/server.key'}]},
}))
