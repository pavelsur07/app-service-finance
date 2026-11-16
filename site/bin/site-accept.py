#!/usr/bin/env python3
"""Sequential local acceptance; no retries, one disposable CLI container."""
import datetime
import hashlib
import json
import os
from pathlib import Path
import shlex
import signal
import subprocess
import sys
import time
import uuid

ROOT = Path(__file__).resolve().parents[2]


def fingerprint():
    paths = subprocess.check_output(
        ['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], cwd=ROOT)
    digest = hashlib.sha256()
    for name in sorted(set(paths.split(b'\0')) - {b''}):
        path = ROOT / os.fsdecode(name)
        digest.update(name + b'\0')
        if path.is_symlink():
            digest.update(b'link:' + os.fsencode(os.readlink(path)))
        elif path.is_file():
            digest.update(str(path.stat().st_mode).encode() + b'\0')
            with path.open('rb') as source:
                for chunk in iter(lambda: source.read(1024 * 1024), b''):
                    digest.update(chunk)
        else:
            digest.update(b'missing')
    return digest.hexdigest()


def host_memory():
    result = {'host_available_bytes': None, 'cgroup_limit_bytes': None,
              'cgroup_current_bytes': None, 'available_bytes': None}
    try:
        for line in Path('/proc/meminfo').read_text().splitlines():
            if line.startswith('MemAvailable:'):
                result['host_available_bytes'] = int(line.split()[1]) * 1024
        # Resolve this process's cgroup, including non-namespaced hosts.
        groups = Path('/proc/self/cgroup').read_text().splitlines()
        for group in groups:
            _, controllers, relative = group.split(':', 2)
            if controllers == '':
                base = Path('/sys/fs/cgroup') / relative.lstrip('/')
                names = ('memory.max', 'memory.current')
            elif 'memory' in controllers.split(','):
                base = Path('/sys/fs/cgroup/memory') / relative.lstrip('/')
                names = ('memory.limit_in_bytes', 'memory.usage_in_bytes')
            else:
                continue
            # Walk parent limits too: the effective quota may live above us.
            limits = []
            while base.is_relative_to('/sys/fs/cgroup'):
                try:
                    limit = (base / names[0]).read_text().strip()
                    current = int((base / names[1]).read_text())
                    if limit != 'max' and int(limit) < 2**60:
                        limits.append((int(limit) - current, int(limit), current))
                except (OSError, ValueError):
                    pass
                base = base.parent
            if limits:
                remaining, limit, current = min(limits)
                result.update(cgroup_limit_bytes=limit, cgroup_current_bytes=current)
                result['available_bytes'] = max(0, remaining)
        available = result['host_available_bytes']
        if available is not None:
            result['available_bytes'] = min(available, result['available_bytes']) if result['available_bytes'] is not None else available
    except (OSError, ValueError):
        pass
    return result


class Acceptance:
    def __init__(self):
        stamp = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        self.name = f'vf-accept-{uuid.uuid4().hex[:12]}'
        self.artifacts = ROOT / 'site/var/accept' / f'{stamp}-{self.name}'
        self.artifacts.mkdir(parents=True, mode=0o700)
        self.compose = shlex.split(os.environ.get('DOCKER_COMPOSE', 'docker compose'))
        self.start = fingerprint()
        self.started = False
        self.creating = False
        self.cancelled = False

    def interrupt(self, signum, frame):
        # Let an accepted daemon create complete before removing its container.
        if self.creating:
            self.cancelled = True
        else:
            raise KeyboardInterrupt

    def run(self, label, command, *, check=True):
        print(f'[{label}] {shlex.join(command)}', flush=True)
        start = time.monotonic()
        memory = host_memory()
        code = 130
        try:
            with (self.artifacts / f'{label}.log').open('wb') as log:
                result = subprocess.run(command, cwd=ROOT, stdout=log, stderr=subprocess.STDOUT,
                                        stdin=subprocess.DEVNULL,
                                        start_new_session=self.creating or label == 'cleanup')
                code = result.returncode
        finally:
            record = {'label': label, 'command': command, 'exit_code': code,
                      'duration_seconds': round(time.monotonic() - start, 3),
                      'memory_before': memory, 'memory_after': host_memory()}
            with (self.artifacts / 'commands.jsonl').open('a') as log:
                log.write(json.dumps(record) + '\n')
        print(f'[{label}] exit={result.returncode}, {record["duration_seconds"]}s', flush=True)
        if check and result.returncode:
            # Do not echo application output, which can contain connection strings.
            print(f'Ошибка: журнал {self.artifacts / (label + ".log")}', file=sys.stderr)
            raise subprocess.CalledProcessError(result.returncode, command)
        return result.returncode

    def cli(self, label, *args):
        self.run(label, ['docker', 'exec', self.name, *args])

    def execute(self):
        signal.signal(signal.SIGTERM, self.interrupt)
        signal.signal(signal.SIGINT, self.interrupt)
        code = 1
        try:
            self.run('docker', ['docker', 'info', '--format', '{{.MemTotal}}'])
            print('Память хоста (null = измерение недоступно): ' + json.dumps(host_memory()), flush=True)
            self.run('database', self.compose + ['exec', '-T', 'site-postgres',
                                               'pg_isready', '-U', 'app', '-d', 'app', '-t', '5'])
            # Set before run: even a partially failed container start must clean up.
            self.started = True
            self.creating = True
            try:
                self.run('container', self.compose + ['run', '-d', '--no-deps', '--name', self.name,
                         '-e', 'XDEBUG_MODE=off', '-e', 'COMPOSER_PROCESS_TIMEOUT=0',
                         '-e', 'COMPOSER_HOME=/tmp/composer', 'site-php-cli', 'sleep', 'infinity'])
            finally:
                self.creating = False
            if self.cancelled:
                raise KeyboardInterrupt
            self.cli('preflight', 'php', '-r', '''
                foreach (['vendor/autoload.php', 'vendor/bin/phpstan', 'vendor/bin/php-cs-fixer',
                          'vendor/phpunit/phpunit/phpunit', '.env.test'] as $file) {
                    if (!is_readable($file)) { fwrite(STDERR, "Missing dependency: $file\\n"); exit(1); }
                }
                foreach (['var/cache', 'var/log', 'var/test'] as $dir) {
                    if (!is_dir($dir) && !mkdir($dir, 0775, true)) { exit(1); }
                    $probe = $dir . '/.accept-' . bin2hex(random_bytes(8));
                    if (file_put_contents($probe, 'ok') === false) { exit(1); }
                    unlink($probe);
                }
            ''')
            self.cli('resource-snapshot', 'php', '-r', '''
                // resource-snapshot: Docker daemon may be on another host.
                $result = [];
                foreach (['/proc/meminfo', '/sys/fs/cgroup/memory.max', '/sys/fs/cgroup/memory.current',
                          '/sys/fs/cgroup/memory.peak', '/sys/fs/cgroup/memory/memory.limit_in_bytes',
                          '/sys/fs/cgroup/memory/memory.usage_in_bytes'] as $path) {
                    $result[$path] = is_readable($path) ? trim(file_get_contents($path)) : null;
                }
                echo json_encode($result), "\\n";
            ''')
            self.cli('create-db', 'php', 'bin/console', 'doctrine:database:create', '--if-not-exists', '--env=test')
            self.cli('migrate', 'php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction', '--env=test')
            self.cli('cache', 'php', 'bin/console', 'cache:warmup', '--env=test')
            self.cli('style', 'composer', 'cs:check')
            self.cli('strict-types', 'composer', 'cs:strict-types')
            self.cli('stan', 'composer', 'exec', '--', 'phpstan', '--configuration=phpstan.local.neon',
                     '--memory-limit=2G', 'analyse')
            for suite in ('unit', 'integration', 'functional'):
                self.cli(suite, 'composer', f'test:{suite}')
            code = 0
        except subprocess.CalledProcessError as error:
            code = error.returncode if error.returncode > 0 else 128 - error.returncode
        except (OSError, KeyboardInterrupt) as error:
            print(f'Приёмка прервана: {type(error).__name__}', file=sys.stderr)
        finally:
            # Finish cleanup even if the terminal/harness interrupts repeatedly.
            signal.signal(signal.SIGINT, signal.SIG_IGN)
            signal.signal(signal.SIGTERM, signal.SIG_IGN)
            if self.started:
                cleanup = self.run('cleanup', ['docker', 'rm', '-f', self.name], check=False)
                if cleanup and code == 0:
                    code = cleanup
            end = fingerprint()
            valid = end == self.start
            if not valid:
                print('Исходники изменились во время проверки: результат недействителен.', file=sys.stderr)
                code = code or 1
            summary = {'source_start': self.start, 'source_end': end,
                       'valid': valid, 'exit_code': code}
            (self.artifacts / 'summary.json').write_text(json.dumps(summary, indent=2) + '\n')
            print(f'Артефакты: {self.artifacts}', flush=True)
        return code


if __name__ == '__main__':
    os.umask(0o077)
    sys.exit(Acceptance().execute())
