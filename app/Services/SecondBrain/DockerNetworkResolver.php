<?php

namespace App\Services\SecondBrain;

/**
 * Resolves the docker network the org containers should join and the internal
 * base URL they post events to. Mirrors the battle-tested resolution used by
 * App\Services\IsolatedAgentTaskExecutor (config first, then `docker inspect`
 * of self / the network) so it also works under Coolify, where the compose
 * project and the `nginx` container get random name suffixes.
 */
class DockerNetworkResolver
{
    private ?string $networkCache = null;

    /** The docker network name org containers attach to. */
    public function network(): string
    {
        if ($this->networkCache !== null) {
            return $this->networkCache;
        }

        $configured = trim((string) config('second_brain.network', ''));
        if ($configured !== '') {
            return $this->networkCache = $configured;
        }

        $hostname = (string) gethostname();
        if ($hostname === '') {
            return $this->networkCache = 'bridge';
        }

        $output = (string) shell_exec(sprintf(
            "docker inspect %s --format '{{range \$net, \$_ := .NetworkSettings.Networks}}{{\$net}}\n{{end}}' 2>/dev/null",
            escapeshellarg($hostname),
        ));

        $skip = ['bridge', 'host', 'none', 'coolify'];
        foreach (explode("\n", $output) as $net) {
            $net = trim($net);
            if ($net !== '' && ! in_array($net, $skip, true)) {
                return $this->networkCache = $net;
            }
        }

        return $this->networkCache = 'bridge';
    }

    /** Base URL (scheme+host) the container posts brain events to. */
    public function gatewayBaseUrl(): string
    {
        $configured = trim((string) config('second_brain.internal_base_url', ''));
        if ($configured !== '' && $configured !== 'http://nginx') {
            return rtrim($configured, '/');
        }

        $network = $this->network();
        $output = (string) shell_exec(sprintf(
            "docker network inspect %s --format '{{range .Containers}}{{.Name}}\n{{end}}' 2>/dev/null",
            escapeshellarg($network),
        ));

        foreach (explode("\n", $output) as $name) {
            $name = trim($name);
            if ($name === 'nginx' || str_starts_with($name, 'nginx-')) {
                return 'http://'.$name;
            }
        }

        return $configured !== '' ? rtrim($configured, '/') : 'http://nginx';
    }
}
