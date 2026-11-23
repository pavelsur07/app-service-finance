#!/usr/bin/env python3
"""Resolve releases from trusted Actions artifacts, never from a Docker tag alone."""
import argparse
import io
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import zipfile

APPLICATIONS = ('site-nginx', 'site-php-fpm', 'site-php-cli', 'land-nginx',
                'ozon-fetcher', 'wb-fetcher', 'ai-service', 'account-nginx', 'admin-nginx')
INFRASTRUCTURE = ('traefik', 'postgres', 'redis')
SERVICES = {'traefik': 'traefik', 'land': 'land-nginx', 'account': 'account-nginx',
            'admin': 'admin-nginx', 'site': 'site-nginx', 'site-php-fpm': 'site-php-fpm',
            'site-php-cli': 'site-php-cli', 'site-postgres': 'postgres', 'site-redis': 'redis',
            'scheduler': 'site-php-cli', 'ozon-fetcher': 'ozon-fetcher', 'wb-fetcher': 'wb-fetcher',
            'ai-service': 'ai-service', 'ai-service-worker': 'ai-service',
            'ai-service-eval-worker': 'ai-service'}
for worker in ('sync', 'pipeline', 'wb-finance', 'ads'):
    SERVICES[f'site-messenger-worker-{worker}'] = 'site-php-cli'
GATES = {'🧪 Unit tests', '🎨 PHP code style', '🔬 Static analysis',
         '🧪 Integration tests 1/3', '🧪 Integration tests 2/3', '🧪 Integration tests 3/3',
         '🧪 Functional tests 1/3', '🧪 Functional tests 2/3',
         '🧪 Functional tests 3/3', '🐹 Go gates (shared/fetchkit)',
         '🐹 Go gates (ozon-fetcher)', '🐹 Go gates (wb-fetcher)',
         '🐍 ai-service gates', 'SPA checks (account)', 'SPA checks (admin)',
         'SPA real backend and HTTPS isolation (dev)',
         'SPA real backend and HTTPS isolation (preview)',
         'SPA real backend and HTTPS isolation (production)', 'migrations-empty-db', '🔍 Check API types sync'}
GATE_IDS = ('unit-tests', 'cs-check', 'stan', 'backend-tests', 'services-go',
            'services-python', 'spa-checks', 'spa-auth-e2e', 'migrations-empty-db', 'api-types-check',
            'build-and-push')


def release_sha():
    sha = os.environ['GITHUB_SHA']
    if not re.fullmatch('[0-9a-f]{40}', sha):
        raise ValueError('GITHUB_SHA must be a full commit SHA')
    if os.environ.get('GITHUB_EVENT_NAME') == 'workflow_dispatch' and os.environ.get('PRODUCTION_ACTION', 'none') != 'none':
        if os.environ.get('GITHUB_REF') != 'refs/heads/master' or os.environ.get('RELEASE_SHA') != sha:
            raise ValueError('Production requires master and release_sha equal to the full workflow SHA')
    return sha


def api(endpoint):
    result = subprocess.run(['gh', 'api', endpoint], capture_output=True)
    if result.returncode:
        raise RuntimeError(f'GitHub API request failed (exit {result.returncode}): {endpoint}')
    return result.stdout


def pages(endpoint, key):
    for page in range(1, 1001):
        separator = '&' if '?' in endpoint else '?'
        values = json.loads(api(f'{endpoint}{separator}per_page=100&page={page}'))[key]
        yield from values
        if len(values) < 100:
            return
    raise RuntimeError('GitHub pagination exceeded the safety limit')


def image_repository(name):
    return f'ghcr.io/pavelsur07/{name}' if name in APPLICATIONS else f'docker.io/library/{name}'


def validate(manifest, sha, repository):
    if (not isinstance(manifest, dict) or manifest.get('schema') != 1 or manifest.get('sha') != sha
            or manifest.get('repository') != repository):
        return False
    images = manifest.get('images', {})
    if not isinstance(images, dict) or set(images) != set(APPLICATIONS) | set(INFRASTRUCTURE):
        return False
    attempts = manifest.get('build_attempts', {})
    if (not isinstance(attempts, dict) or set(attempts) != set(APPLICATIONS)
            or type(manifest.get('run_attempt')) is not int
            or any(type(value) is not int or not 1 <= value <= manifest['run_attempt'] for value in attempts.values())):
        return False
    return all(isinstance(ref, str) and re.fullmatch(
        re.escape(image_repository(name)) + r'@sha256:[0-9a-f]{64}', ref)
        for name, ref in images.items())


def registry_digest(reference, *, allow_missing=False):
    result = subprocess.run(['docker', 'buildx', 'imagetools', 'inspect', reference,
                             '--format', '{{json .Manifest}}'], capture_output=True, text=True)
    if result.returncode:
        error = result.stderr.lower().strip()
        missing = 'manifest unknown' in error or re.fullmatch(
            r'(error: )?' + re.escape(reference.lower()) + r': not found', error)
        access_error = any(word in error for word in ('unauthorized', 'denied', 'forbidden', 'authorize', 'token'))
        if allow_missing and missing and not access_error:
            return None
        raise RuntimeError(f'Registry access/transport error (exit {result.returncode}): {reference}')
    digest = json.loads(result.stdout)['digest']
    if not re.fullmatch(r'sha256:[0-9a-f]{64}', digest):
        raise ValueError('Registry returned an invalid digest')
    if '@' in reference and reference.split('@', 1)[1] != digest:
        raise ValueError('Registry digest does not match the verified release')
    return digest


def write_bundle(directory, manifest):
    directory.mkdir(parents=True, exist_ok=True)
    (directory / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    override = {'services': {name: {'image': manifest['images'][image]}
                             for name, image in SERVICES.items()}}
    (directory / 'compose.digest.json').write_text(json.dumps(override, indent=2) + '\n')
    (directory / 'infrastructure.tsv').write_text(''.join(
        f'{name}\t{manifest["images"][name]}\n' for name in INFRASTRUCTURE))


def trusted_run(run, sha, workflow_id, repository):
    return (run.get('workflow_id') == workflow_id and run.get('head_sha') == sha
            and run.get('head_branch') == 'master' and run.get('event') in ('push', 'workflow_dispatch')
            and run.get('path') == '.github/workflows/deploy.yml'
            and run.get('head_repository', {}).get('full_name') == repository)


def resolve(directory, sha):
    if (os.environ.get('GITHUB_EVENT_NAME') == 'pull_request'
            or os.environ.get('GITHUB_REF') != 'refs/heads/master'
            or (os.environ.get('GITHUB_EVENT_NAME') == 'workflow_dispatch'
                and os.environ.get('PRODUCTION_ACTION', 'none') == 'none')):
        return False
    repository = os.environ['GITHUB_REPOSITORY']
    prefix = f'repos/{repository}/actions'
    workflow_id = json.loads(api(f'{prefix}/workflows/deploy.yml'))['id']
    runs = pages(f'{prefix}/workflows/deploy.yml/runs?head_sha={sha}&branch=master', 'workflow_runs')
    for run in runs:
        if not trusted_run(run, sha, workflow_id, repository):
            continue
        for artifact in pages(f'{prefix}/runs/{run["id"]}/artifacts', 'artifacts'):
            if artifact.get('expired') or not re.fullmatch(f'release-{sha}-[0-9]+', artifact.get('name', '')):
                continue
            origin = artifact.get('workflow_run', {})
            if origin.get('id') != run['id'] or origin.get('head_sha') != sha or origin.get('head_branch') != 'master':
                continue
            try:
                raw = api(f'{prefix}/artifacts/{artifact["id"]}/zip')
                with zipfile.ZipFile(io.BytesIO(raw)) as archive:
                    entry = archive.getinfo('manifest.json')
                    if entry.file_size > 65536:
                        continue
                    manifest = json.loads(archive.read(entry))
                if not validate(manifest, sha, repository):
                    continue
                attempt = manifest.get('run_attempt')
                if (manifest.get('run_id') != run['id'] or manifest.get('workflow_id') != workflow_id
                        or type(attempt) is not int or attempt < 1
                        or artifact['name'] != f'release-{sha}-{attempt}'):
                    continue
                origin_run = json.loads(api(f'{prefix}/runs/{run["id"]}/attempts/{attempt}'))
                if not trusted_run(origin_run, sha, workflow_id, repository):
                    continue
                required = GATES | {f'Build image ({image})' for image in APPLICATIONS} | {'Release manifest'}
                latest = {}
                for checked_attempt in range(1, attempt + 1):
                    for job in pages(f'{prefix}/runs/{run["id"]}/attempts/{checked_attempt}/jobs', 'jobs'):
                        latest[job['name']] = (checked_attempt, job)
                successes = {name for name, (_, job) in latest.items()
                             if job.get('conclusion') == 'success' and job.get('status') == 'completed'}
                if not required <= successes:
                    continue
                if any(latest[f'Build image ({name})'][0] != build_attempt
                       for name, build_attempt in manifest['build_attempts'].items()):
                    continue
            except (KeyError, ValueError, TypeError, zipfile.BadZipFile):
                # An invalid/untrusted artifact is a cache miss, never authority.
                continue
            if any(registry_digest(ref, allow_missing=True) is None for ref in manifest['images'].values()):
                continue
            write_bundle(directory, manifest)
            return True
    return False


def publish(directory, sha):
    needs = json.loads(os.environ['NEEDS_JSON'])
    if any(needs.get(job, {}).get('result') != 'success' for job in GATE_IDS):
        raise ValueError('All mandatory gates and builds must succeed before publishing a release')
    repository = os.environ['GITHUB_REPOSITORY']
    workflow_id = json.loads(api(f'repos/{repository}/actions/workflows/deploy.yml'))['id']
    manifest = dict(schema=1, sha=sha, repository=repository, workflow_id=workflow_id,
                    run_id=int(os.environ['GITHUB_RUN_ID']), run_attempt=int(os.environ['GITHUB_RUN_ATTEMPT']), images={}, build_attempts={})
    for name in APPLICATIONS:
        descriptors = [json.loads(path.read_text()) for path in (directory / 'descriptors').glob(f'**/{name}.json')]
        if not descriptors:
            raise ValueError(f'Missing verified build descriptor for {name}')
        descriptor = max(descriptors, key=lambda value: value.get('run_attempt', 0))
        if (descriptor.get('sha') != sha or descriptor.get('run_id') != manifest['run_id']
                or type(descriptor.get('run_attempt')) is not int
                or not 1 <= descriptor['run_attempt'] <= manifest['run_attempt'] or descriptor.get('image') != name
                or not re.fullmatch(r'sha256:[0-9a-f]{64}', descriptor.get('digest', ''))):
            raise ValueError(f'Invalid build provenance for {name}')
        ref = image_repository(name) + '@' + descriptor['digest']
        registry_digest(ref)
        manifest['images'][name] = ref
        manifest['build_attempts'][name] = descriptor['run_attempt']
    infrastructure = dict(line.split('\t', 1) for line in (directory / 'infrastructure.tsv').read_text().splitlines())
    if set(infrastructure) != set(INFRASTRUCTURE):
        raise ValueError('Missing installed infrastructure digests')
    for name, ref in infrastructure.items():
        if not re.fullmatch(re.escape(image_repository(name)) + r'@sha256:[0-9a-f]{64}', ref):
            raise ValueError('Invalid installed infrastructure digest')
        registry_digest(ref)
        manifest['images'][name] = ref
    if not validate(manifest, sha, repository):
        raise ValueError('Cannot publish incomplete release')
    write_bundle(directory, manifest)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('action', choices=['resolve', 'publish', 'ancestry'])
    parser.add_argument('--directory', type=Path, required=True)
    parser.add_argument('--installed')
    args = parser.parse_args()
    sha = release_sha()
    if args.action == 'ancestry':
        if not args.installed or not re.fullmatch('[0-9a-f]{40}', args.installed):
            raise ValueError('Installed version must be a full SHA')
        if subprocess.run(['git', 'merge-base', '--is-ancestor', args.installed, sha],
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode:
            raise ValueError('Refusing an older or unrelated production release')
    elif args.action == 'publish':
        publish(args.directory, sha)
    else:
        reuse = resolve(args.directory, sha)
        output = f'reuse={str(reuse).lower()}\n'
        if os.environ.get('GITHUB_OUTPUT'):
            with open(os.environ['GITHUB_OUTPUT'], 'a') as stream:
                stream.write(output)
        print(output, end='')


if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, ValueError, KeyError, OSError) as error:
        print(f'::error::{error}', file=sys.stderr)
        sys.exit(1)
