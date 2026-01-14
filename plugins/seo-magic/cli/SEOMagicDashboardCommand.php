<?php

namespace Grav\Plugin\Console;

use Grav\Common\Grav;
use Grav\Console\ConsoleCommand;
use Grav\Plugin\SeoMagicPlugin;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

class SEOMagicDashboardCommand extends ConsoleCommand
{
    protected function configure(): void
    {
        $this
            ->setName('dashboard-scan')
            ->setDescription('Run the SEO-Magic dashboard crawl and update admin progress state.')
            ->addOption('mode', null, InputOption::VALUE_REQUIRED, 'Scan mode (full|changed)', 'full')
            ->addOption('no-history', null, InputOption::VALUE_NONE, 'Skip writing a history snapshot when finished');
    }

    protected function serve(): int
    {
        $io = new SymfonyStyle($this->input, $this->output);
        $mode = $this->input->getOption('mode') ?: 'full';
        $mode = in_array($mode, ['full', 'changed'], true) ? $mode : 'full';
        $writeHistory = !$this->input->getOption('no-history');

        $io->title(sprintf('SEO-Magic dashboard scan (%s)', $mode));

        $envHost = getenv('GRAV_HTTP_HOST');
        if ($envHost) {
            $_SERVER['HTTP_HOST'] = $envHost;
            $_SERVER['SERVER_NAME'] = $envHost;
        }
        $envScheme = getenv('GRAV_HTTP_SCHEME');
        if ($envScheme) {
            $envScheme = strtolower(rtrim($envScheme, ':/'));
            $_SERVER['REQUEST_SCHEME'] = $envScheme;
            if ($envScheme === 'https') {
                $_SERVER['HTTPS'] = 'on';
            }
        }
        $envPort = getenv('GRAV_HTTP_PORT');
        if ($envPort) {
            $_SERVER['SERVER_PORT'] = $envPort;
        }
        $envBase = getenv('GRAV_BASE_URI');
        if ($envBase) {
            $_SERVER['BASE_URI'] = $envBase;
            $_SERVER['REQUEST_URI'] = rtrim($envBase, '/') . '/';
        }

        $this->initializePlugins();
        $grav = Grav::instance();
        $plugins = $grav['plugins'];
        $log = isset($grav['log']) ? $grav['log'] : null;
        if (!empty($envHost) && !empty($envScheme)) {
            $portSegment = '';
            if (!empty($envPort) && !in_array((int)$envPort, [80, 443], true)) {
                $portSegment = ':' . $envPort;
            }
            $baseSegment = $envBase ? rtrim($envBase, '/') : '';
            try {
                $grav['config']->set('system.custom_base_url', $envScheme . '://' . $envHost . $portSegment . $baseSegment);
            } catch (\Throwable $ignored) {}
        }
        try {
            $grav['pages']->init();
        } catch (\Throwable $initError) {
            if ($log) {
                try { $log->warning('SEO-Magic dashboard CLI failed to pre-initialize pages', ['error' => $initError->getMessage()]); } catch (\Throwable $ignored) {}
            }
        }
        $pluginInstance = null;
        if (is_iterable($plugins)) {
            foreach ($plugins as $instance) {
                if ($instance instanceof SeoMagicPlugin) {
                    $pluginInstance = $instance;
                    break;
                }
            }
        }
        if ($log) {
            try { $log->info('SEO-Magic dashboard CLI started', ['mode' => $mode]); } catch (\Throwable $e) {}
        }
        /** @var SeoMagicPlugin|null $plugin */
        $plugin = $pluginInstance;

        if (!$plugin instanceof SeoMagicPlugin) {
            $io->error('SEO-Magic plugin is not loaded.');
            return 1;
        }

        try {
            $plugin->executeScanJob($mode, true, $writeHistory, function ($processed, $total, $route, $url, $code) use ($io) {
                $io->text(sprintf('%d/%d [%s] %s (%s)', $processed, $total, $route, $code, $url));
            });
            $io->success('Scan completed');
            if ($log) {
                try { $log->info('SEO-Magic dashboard CLI finished', ['mode' => $mode, 'status' => 'success']); } catch (\Throwable $e) {}
            }
            return 0;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            if ($log) {
                try { $log->error('SEO-Magic dashboard CLI failed', ['mode' => $mode, 'error' => $e->getMessage()]); } catch (\Throwable $ignored) {}
            }
            return 1;
        }
    }
}
