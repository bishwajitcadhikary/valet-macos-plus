<?php

use Illuminate\Container\Container;
use Silly\Application;
use Symfony\Component\Console\Command\Command as CommandAlias;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Valet\Drivers\ValetDriver;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;
use function Valet\abbreviate_number;
use function Valet\human_readable_size;
use function Valet\output;
use function Valet\writer;

/**
 * Load correct autoloader depending on install location.
 */
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
} elseif (file_exists(__DIR__ . '/../../../autoload.php')) {
    require_once __DIR__ . '/../../../autoload.php';
} else {
    require_once getenv('HOME') . '/.composer/vendor/autoload.php';
}

/**
 * Create the application.
 */
Container::setInstance(new Container);

$version = '4.7.1';

$app = new Application('Laravel Valet', $version);

$app->setDispatcher($dispatcher = new EventDispatcher());

$dispatcher->addListener(
    ConsoleEvents::COMMAND,
    function (ConsoleCommandEvent $event) {
        writer($event->getOutput());
    });

Upgrader::onEveryRun();

/**
 * Install Valet and any required services.
 */
$app->command('install', function () {
    Nginx::stop();

    Configuration::install();
    output();
    Nginx::install();
    output();
    PhpFpm::install();
    output();
    DnsMasq::install(Configuration::read()['tld']);
    output();
    Site::renew();
    output();
    Nginx::restart();
    output();
    Valet::symlinkToUsersBin();
    output();
    MySql::install();
    output();
    RedisServer::install();
    output();
    Mailpit::install();
    output();
    PhpMyAdmin::install();

    info("Valet MacOs Plus has been installed successfully.");
})->descriptions('Install the Valet services');

/**
 * Output the status of Valet and its installed services and config.
 */
$app->command('status', function (OutputInterface $output) {
    info('Checking status...');

    $status = Status::check();

    if ($status['success']) {
        info("Valet status: Healthy");
    } else {
        warning("Valet status: Error");
    }

    table(['Check', 'Success?'], $status['output']);

    if ($status['success']) {
        return CommandAlias::SUCCESS;
    }

    info(PHP_EOL . 'Debug suggestions:');
    info($status['debug']);

    return CommandAlias::FAILURE;
})->descriptions('Output the status of Valet and its installed services and config.');

/**
 * Most commands are available only if valet is installed.
 */
if (is_dir(VALET_HOME_PATH)) {
    /**
     * Upgrade helper: ensure the tld config exists and the loopback config exists.
     */
    Configuration::ensureBaseConfiguration();

    /**
     * Get or set the TLD currently being used by Valet.
     */
    $app->command('tld [tld]', function (InputInterface $input, OutputInterface $output, $tld = null) {
        if ($tld === null) {
            output(Configuration::read()['tld']);

            return CommandAlias::SUCCESS;
        }

        $confirm = confirm(
            'Using a custom TLD is no longer officially supported and may lead to unexpected behavior. Do you wish to proceed?',
            false
        );

        if (!$confirm) {
            warning('No new Valet tld was set.');

            return CommandAlias::FAILURE;
        }

        DnsMasq::updateTld(
            $oldTld = Configuration::read()['tld'],
            $tld = trim($tld, '.')
        );

        Configuration::updateKey('tld', $tld);

        Site::resecureForNewConfiguration(['tld' => $oldTld], ['tld' => $tld]);
        PhpFpm::restart();
        Nginx::restart();

        info('Your Valet TLD has been updated to [' . $tld . '].');

        return CommandAlias::SUCCESS;
    }, ['domain'])->descriptions('Get or set the TLD used for Valet sites.');

    /**
     * Get or set the loopback address currently being used by Valet.
     */
    $app->command('loopback [loopback]', function (InputInterface $input, OutputInterface $output, $loopback = null) {
        if ($loopback === null) {
            output(Configuration::read()['loopback']);

            return CommandAlias::SUCCESS;
        }

        if (filter_var($loopback, FILTER_VALIDATE_IP) === false) {
            warning('[' . $loopback . '] is not a valid IP address');

            return CommandAlias::FAILURE;
        }

        $oldLoopback = Configuration::read()['loopback'];

        Configuration::updateKey('loopback', $loopback);

        DnsMasq::refreshConfiguration();
        Site::aliasLoopback($oldLoopback, $loopback);
        Site::resecureForNewConfiguration(['loopback' => $oldLoopback], ['loopback' => $loopback]);
        PhpFpm::restart();
        Nginx::installServer();
        Nginx::restart();

        info("Your Valet loopback address has been updated to [$loopback].");

        return CommandAlias::SUCCESS;
    })->descriptions('Get or set the loopback address used for Valet sites');

    /**
     * Add the current working directory to the paths configuration.
     */
    $app->command('park [path]', function ($path = null) {
        Configuration::addPath($path ?: getcwd());

        info(($path === null ? 'This' : "The [$path]") . " directory has been added to Valet's paths.");
    })->descriptions('Register the current working (or specified) directory with Valet');

    /**
     * Get all the current sites within paths parked with 'park {path}'.
     */
    $app->command('parked', function (OutputInterface $output) {
        $parked = Site::parked();

        table(['Site', 'SSL', 'URL', 'Path'], $parked->all());
    })->descriptions('Display all the current sites within parked paths');

    /**
     * Remove the current working directory from the paths configuration.
     */
    $app->command('forget [path]', function (OutputInterface $output, $path = null) {
        Configuration::removePath($path ?: getcwd());

        info(($path === null ? 'This' : "The [{$path}]") . " directory has been removed from Valet's paths.");
    }, ['unpark'])->descriptions('Remove the current working (or specified) directory from Valet\'s list of paths');

    /**
     * Register a symbolic link with Valet.
     */
    $app->command('link [name] [--secure] [--isolate]', function ($name, $secure, $isolate) {
        $linkPath = Site::link(getcwd(), $name = $name ?: basename(getcwd()));

        info('A [' . $name . '] symbolic link has been created in [' . $linkPath . '].');

        if ($secure) {
            $this->runCommand('secure ' . $name);
        }

        if ($isolate) {
            if (Site::phpRcVersion($name, getcwd())) {
                $this->runCommand('isolate --site=' . $name);
            } else {
                warning('Valet could not determine which PHP version to use for this site.');
            }
        }
    })->descriptions('Link the current working directory to Valet', [
        '--secure' => 'Link the site with a trusted TLS certificate.',
        '--isolate' => 'Isolate the site to the PHP version specified in the current working directory\'s .valetrc file.',
    ]);

    /**
     * Display all of the registered symbolic links.
     */
    $app->command('links', function (OutputInterface $output) {
        $links = Site::links();

        table(['Site', 'SSL', 'URL', 'Path', 'PHP Version'], $links->all());
    })->descriptions('Display all of the registered Valet links');

    /**
     * Unlink a link from the Valet links directory.
     */
    $app->command('unlink [name]', function (OutputInterface $output, $name) {
        $name = Site::unlink($name);
        info('The [' . $name . '] symbolic link has been removed.');

        if (Site::isSecured($name)) {
            info('Unsecuring ' . $name . '...');

            Site::unsecure(Site::domain($name));

            Nginx::restart();
        }
    })->descriptions('Remove the specified Valet link');

    /**
     * Secure the given domain with a trusted TLS certificate.
     */
    $app->command('secure [domain] [--expireIn=]', function (OutputInterface $output, $domain = null, $expireIn = 368) {
        $url = Site::domain($domain);

        Site::secure($url, null, $expireIn);

        Nginx::restart();

        info('The [' . $url . '] site has been secured with a fresh TLS certificate.');
    })->descriptions('Secure the given domain with a trusted TLS certificate', [
        '--expireIn' => 'The amount of days the self signed certificate is valid for. Default is set to "368"',
    ]);

    /**
     * Stop serving the given domain over HTTPS and remove the trusted TLS certificate.
     */
    $app->command('unsecure [domain] [--all]', function (OutputInterface $output, $domain = null, $all = null) {
        if ($all) {
            Site::unsecureAll();

            Nginx::restart();

            info('All Valet sites will now serve traffic over HTTP.');

            return;
        }

        $url = Site::domain($domain);

        Site::unsecure($url);

        Nginx::restart();

        info('The [' . $url . '] site will now serve traffic over HTTP.');
    })->descriptions('Stop serving the given domain over HTTPS and remove the trusted TLS certificate');

    /**
     * Display all of the currently secured sites.
     */
    $app->command('secured [--expiring] [--days=]', function (OutputInterface $output, $expiring = null, $days = 60) {
        $now = (new Datetime())->add(new DateInterval('P' . $days . 'D'));
        $sites = collect(Site::securedWithDates())
            ->when($expiring, fn($collection) => $collection->filter(fn($row) => $row['exp'] < $now))
            ->map(function ($row) {
                return [
                    'Site' => $row['site'],
                    'Valid Until' => $row['exp']->format('Y-m-d H:i:s T'),
                ];
            })
            ->when($expiring, fn($collection) => $collection->sortBy('Valid Until'));

        table(['Site', 'Valid Until'], $sites->all());

        return CommandAlias::SUCCESS;
    })->descriptions('Display all of the currently secured sites', [
        '--expiring' => 'Limits the results to only sites expiring within the next 60 days.',
        '--days' => 'To be used with --expiring. Limits the results to only sites expiring within the next X days. Default is set to 60.',
    ]);

    /**
     * Renews all domains with a trusted TLS certificate.
     */
    $app->command('renew [--expireIn=]', function (OutputInterface $output, $expireIn = 368) {
        Site::renew($expireIn);
        Nginx::restart();
    })->descriptions('Renews all domains with a trusted TLS certificate.', [
        '--expireIn' => 'The amount of days the self signed certificate is valid for. Default is set to "368"',
    ]);

    /**
     * Create an Nginx proxy config for the specified domain.
     */
    $app->command('proxy domain host [--secure]', function (OutputInterface $output, $domain, $host, $secure) {
        Site::proxyCreate($domain, $host, $secure);
        Nginx::restart();
    })->descriptions('Create an Nginx proxy site for the specified host. Useful for docker, mailhog etc.', [
        '--secure' => 'Create a proxy with a trusted TLS certificate',
    ]);

    /**
     * Delete an Nginx proxy config.
     */
    $app->command('unproxy domain', function (OutputInterface $output, $domain) {
        Site::proxyDelete($domain);
        Nginx::restart();
    })->descriptions('Delete an Nginx proxy config.');

    /**
     * Display all of the sites that are proxies.
     */
    $app->command('proxies', function (OutputInterface $output) {
        $proxies = Site::proxies();

        table(['Site', 'SSL', 'URL', 'Host'], $proxies->all());
    })->descriptions('Display all of the proxy sites');

    /**
     * Display which Valet driver the current directory is using.
     */
    $app->command('which', function (OutputInterface $output) {
        $driver = ValetDriver::assign(getcwd(), basename(getcwd()), '/');

        if ($driver) {
            info('This site is served by [' . get_class($driver) . '].');
        } else {
            warning('Valet could not determine which driver to use for this site.');
        }
    })->descriptions('Display which Valet driver serves the current working directory');

    /**
     * Display all of the registered paths.
     */
    $app->command('paths', function () {
        $paths = Configuration::read()['paths'];

        if (count($paths) > 0) {
            table(['Path'], collect($paths)->map(fn($path) => [$path])->all());
        } else {
            info('No paths have been registered.');
        }
    })->descriptions('Get all of the paths registered with Valet');

    /**
     * Open the current or given directory in the browser.
     */
    $app->command('open [domain]', function ($domain = null) {
        $url = 'http://' . Site::domain($domain);
        CommandLine::runAsUser('open ' . escapeshellarg($url));
    })->descriptions('Open the site for the current (or specified) directory in your browser');

    /**
     * Generate a publicly accessible URL for your project.
     */
    $app->command('share', function () {
        warning('It looks like you are running `cli/valet.php` directly; please use the `valet` script in the project root instead.');

        return CommandAlias::FAILURE;
    })->descriptions('Generate a publicly accessible URL for your project');

    /**
     * Echo the currently tunneled URL.
     */
    $app->command('fetch-share-url [domain]', function ($domain = null) {
        $tool = Configuration::read()['share-tool'] ?? null;

        switch ($tool) {
            case 'expose':
                if ($url = Expose::currentTunnelUrl($domain ?: Site::host(getcwd()))) {
                    output($url);
                }
                break;
            case 'ngrok':
                try {
                    output(Ngrok::currentTunnelUrl(Site::domain($domain)));
                } catch (Throwable $e) {
                    warning($e->getMessage());
                }
                break;
            default:
                info('Please set your share tool with `valet share-tool expose` or `valet share-tool ngrok`.');

                return CommandAlias::FAILURE;
        }

        return CommandAlias::SUCCESS;
    })->descriptions('Get the URL to the current share tunnel (for Expose or ngrok)');

    /**
     * Echo or set the name of the currently-selected share tool (either "ngrok" or "expose").
     */
    $app->command('share-tool [tool]', function ($tool = null) {
        if ($tool === null) {
            output(Configuration::read()['share-tool'] ?? '(not set)');

            return CommandAlias::SUCCESS;
        }

        if ($tool !== 'expose' && $tool !== 'ngrok') {
            warning($tool . ' is not a valid share tool. Please use `ngrok` or `expose`.');

            return CommandAlias::FAILURE;
        }

        Configuration::updateKey('share-tool', $tool);
        info('Share tool set to ' . $tool . '.');

        if ($tool === 'expose') {
            if (Expose::installed()) {
                // @todo: Check it's the right version (has /api/tunnels/)
                // E.g. if (Expose::installedVersion)
                // if (version_compare(Expose::installedVersion(), $minimumExposeVersion) < 0) {
                // prompt them to upgrade
                return CommandAlias::SUCCESS;
            }

            $confirm = confirm('Would you like to install Expose now?', false);

            if (!$confirm) {
                info('Proceeding without installing Expose.');

                return CommandAlias::SUCCESS;
            }

            Expose::ensureInstalled();

            return CommandAlias::SUCCESS;
        }

        if (!Ngrok::installed()) {
            info("In order to share with ngrok, you'll need a version of ngrok installed and managed by Homebrew.");

            $confirm = confirm('Would you like to install ngrok via Homebrew now?', false);

            if (!$confirm) {
                info('Proceeding without installing ngrok.');

                return CommandAlias::SUCCESS;
            }

            Ngrok::ensureInstalled();
        }

        return CommandAlias::SUCCESS;
    })->descriptions('Get the name of the current share tool (Expose or ngrok).');

    /**
     * Set the ngrok auth token.
     */
    $app->command('set-ngrok-token [token]', function (OutputInterface $output, $token = null) {
        output(Ngrok::setToken($token));
    })->descriptions('Set the Ngrok auth token');

    /**
     * Start the daemon services.
     */
    $app->command('start [service]', function (OutputInterface $output, $service) {
        switch ($service) {
            case '':
                DnsMasq::restart();
                PhpFpm::restart();
                Nginx::restart();
                MySql::restart();
                Mailpit::restart();

                info('Valet services have been started.');
                return CommandAlias::SUCCESS;
            case 'dnsmasq':
                DnsMasq::restart();

                info('dnsmasq has been started.');
                return CommandAlias::SUCCESS;
            case 'nginx':
                Nginx::restart();

                info('Nginx has been started.');
                return CommandAlias::SUCCESS;
            case 'php':
                PhpFpm::restart();

                info('PHP has been started.');
                return CommandAlias::SUCCESS;
            case 'mysql':
                MySql::restart();

                info('MySQL has been started.');
                return CommandAlias::SUCCESS;
        }

        warning("Invalid valet service name [$service]");

        return CommandAlias::FAILURE;
    })->descriptions('Start the Valet services');

    /**
     * Restart the daemon services.
     */
    $app->command('restart [service]', function (OutputInterface $output, $service) {
        switch ($service) {
            case '':
                DnsMasq::restart();
                PhpFpm::restart();
                Nginx::restart();
                MySql::restart();
                RedisServer::restart();

                info('Valet services have been restarted.');
                return CommandAlias::SUCCESS;
            case 'dnsmasq':
                DnsMasq::restart();

                info('dnsmasq has been restarted.');
                return CommandAlias::SUCCESS;
            case 'nginx':
                Nginx::restart();

                info('Nginx has been restarted.');
                return CommandAlias::SUCCESS;
            case 'php':
                PhpFpm::restart();

                info('PHP has been restarted.');
                return CommandAlias::SUCCESS;
            case 'mysql':
                MySql::restart();

                info('MySQL has been restarted.');
                return CommandAlias::SUCCESS;
            case 'redis':
                RedisServer::restart();

                info('Redis has been restarted.');
                return CommandAlias::SUCCESS;
        }

        // Handle restarting specific PHP version (e.g. `valet restart php@8.2`)
        if (str_contains($service, 'php')) {
            PhpFpm::restart($normalized = PhpFpm::normalizePhpVersion($service));

            info($normalized . ' has been restarted.');
            return CommandAlias::SUCCESS;
        }

        warning("Invalid valet service name [$service]");
        return CommandAlias::FAILURE;
    })->descriptions('Restart the Valet services');

    /**
     * Stop the daemon services.
     */
    $app->command('stop [service]', function (OutputInterface $output, $service) {
        switch ($service) {
            case '':
                PhpFpm::stopRunning();
                Nginx::stop();
                MySql::stop();
                RedisServer::stop();

                info('Valet core services have been stopped. To also stop dnsmasq, run: valet stop dnsmasq');
                return CommandAlias::SUCCESS;
            case 'all':
                PhpFpm::stopRunning();
                Nginx::stop();
                Dnsmasq::stop();

                info('All Valet services have been stopped.');
                return CommandAlias::SUCCESS;
            case 'nginx':
                Nginx::stop();

                info('Nginx has been stopped.');
                return CommandAlias::SUCCESS;
            case 'php':
                PhpFpm::stopRunning();

                info('PHP has been stopped.');
                return CommandAlias::SUCCESS;
            case 'dnsmasq':
                Dnsmasq::stop();

                info('dnsmasq has been stopped.');
                return CommandAlias::SUCCESS;
            case 'mysql':
                MySql::stop();

                info('MySQL has been stopped.');
                return CommandAlias::SUCCESS;
            case 'redis':
                RedisServer::stop();

                info('Redis has been stopped.');
                return CommandAlias::SUCCESS;
        }

        warning("Invalid valet service name [$service]");
        return CommandAlias::FAILURE;
    })->descriptions('Stop the core Valet services, or all services by specifying "all".');

    /**
     * Uninstall Valet entirely. Requires --force to actually remove; otherwise manual instructions are displayed.
     */
    $app->command('uninstall [--force]', function ($force) {
        if ($force) {
            warning('YOU ARE ABOUT TO UNINSTALL Nginx, PHP, MySQL Dnsmasq and all Valet configs and logs.');

            $confirm = confirm('Are you sure you want to proceed?', false);

            if (!$confirm) {
                warning('Uninstall aborted.');
                return CommandAlias::FAILURE;
            }

            info('Removing certificates for all Secured sites...');
            Site::unsecureAll();

            info('Removing certificate authority...');
            Site::removeCa();

            info('Removing Nginx and configs...');
            Nginx::uninstall();

            info('Removing PHPMyAdmin...');
            PhpMyAdmin::uninstall();

            info('Removing Mailpit...');
            Mailpit::uninstall();

            info('Removing MySQL and configs...');
            MySql::uninstall();

            info('Removing Redis and configs...');
            RedisServer::uninstall();

            info('Removing Dnsmasq and configs...');
            DnsMasq::uninstall();

            info('Removing loopback customization...');
            Site::uninstallLoopback();

            info('Removing Valet configs and customizations...');
            Configuration::uninstall();

            info('Removing PHP versions and configs...');
            PhpFpm::uninstall();

            info('Attempting to unlink Valet from bin path...');
            Valet::unlinkFromUsersBin();

            info('Removing sudoers entries...');
            Brew::removeSudoersEntry();
            Valet::removeSudoersEntry();

            output(Valet::forceUninstallText());
            return CommandAlias::SUCCESS;
        }

        output(Valet::uninstallText());

        // Stop PHP so the ~/.config/valet/valet.sock file is released so the directory can be deleted if desired
        PhpFpm::stopRunning();
        Nginx::stop();
        MySql::stop();
        RedisServer::stop();
        Mailpit::stop();

        return CommandAlias::FAILURE;
    })->descriptions('Uninstall the Valet services', ['--force' => 'Do a forceful uninstall of Valet and related Homebrew pkgs']);

    /**
     * Determine if this is the latest release of Valet.
     */
    $app->command('on-latest-version', function () use ($version) {
        if (Valet::onLatestVersion($version)) {
            output('Yes');
        } else {
            output("Your version of Valet ($version) is not the latest version available.");
            output('Upgrade instructions can be found in the docs: https://laravel.com/docs/valet#upgrading-valet');
        }
    }, ['latest'])->descriptions('Determine if this is the latest version of Valet');

    /**
     * Install the sudoers.d entries so password is no longer required.
     */
    $app->command('trust [--off]', function ($off) {
        if ($off) {
            Brew::removeSudoersEntry();
            Valet::removeSudoersEntry();

            info('Sudoers entries have been removed for Brew and Valet.');
            return CommandAlias::SUCCESS;
        }

        Brew::createSudoersEntry();
        Valet::createSudoersEntry();

        info('Sudoers entries have been added for Brew and Valet.');
        return CommandAlias::SUCCESS;
    })->descriptions('Add sudoers files for Brew and Valet to make Valet commands run without passwords', [
        '--off' => 'Remove the sudoers files so normal sudo password prompts are required.',
    ]);

    /**
     * Allow the user to change the version of php Valet uses.
     */
    $app->command('use [phpVersion] [--force]', function ($phpVersion, $force) {
        if (!$phpVersion) {
            $site = basename(getcwd());
            $linkedVersion = Brew::linkedPhp();

            if ($phpVersion = Site::phpRcVersion($site, getcwd())) {
                info("Found '{$site}/.valetrc' or '{$site}/.valetphprc' specifying version: {$phpVersion}");
                info("Found '{$site}/.valetphprc' specifying version: {$phpVersion}");
            } else {
                $domain = $site . '.' . data_get(Configuration::read(), 'tld');
                if ($phpVersion = PhpFpm::normalizePhpVersion(Site::customPhpVersion($domain))) {
                    info("Found isolated site '{$domain}' specifying version: {$phpVersion}");
                }
            }

            if (!$phpVersion) {
                info("Valet is using {$linkedVersion}.");

                return CommandAlias::SUCCESS;
            }

            if ($linkedVersion == $phpVersion && !$force) {
                info("Valet is already using {$linkedVersion}.");

                return CommandAlias::SUCCESS;
            }
        }

        PhpFpm::useVersion($phpVersion, $force);

        return CommandAlias::SUCCESS;
    })->descriptions('Change the version of PHP used by Valet', [
        'phpVersion' => 'The PHP version you want to use; e.g. php@8.2',
    ]);

    /**
     * Allow the user to change the version of PHP Valet uses to serve the current site.
     */
    $app->command('isolate [phpVersion] [--site=]', function (OutputInterface $output, $phpVersion, $site = null) {
        if (!$site) {
            $site = basename(getcwd());
        }

        if (is_null($phpVersion)) {
            if ($phpVersion = Site::phpRcVersion($site, getcwd())) {
                info("Found '{$site}/.valetrc' or '{$site}/.valetphprc' specifying version: {$phpVersion}");
            } else {
                info(PHP_EOL . 'Please provide a version number. E.g.:');
                info('valet isolate php@8.2');

                return;
            }
        }

        PhpFpm::isolateDirectory($site, $phpVersion);
    })->descriptions('Change the version of PHP used by Valet to serve the current working directory', [
        'phpVersion' => 'The PHP version you want to use; e.g php@8.1',
        '--site' => 'Specify the site to isolate (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * Allow the user to un-do specifying the version of PHP Valet uses to serve the current site.
     */
    $app->command('unisolate [--site=]', function (OutputInterface $output, $site = null) {
        if (!$site) {
            $site = basename(getcwd());
        }

        PhpFpm::unIsolateDirectory($site);
    })->descriptions('Stop customizing the version of PHP used by Valet to serve the current working directory', [
        '--site' => 'Specify the site to un-isolate (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * List isolated sites.
     */
    $app->command('isolated', function (OutputInterface $output) {
        $sites = PhpFpm::isolatedDirectories();

        table(['Path', 'PHP Version'], $sites->all());
    })->descriptions('List all sites using isolated versions of PHP.');

    /**
     * Get the PHP executable path for a site.
     */
    $app->command('which-php [site]', function (OutputInterface $output, $site) {
        $phpVersion = Site::customPhpVersion(
            Site::host($site ?: getcwd()) . '.' . Configuration::read()['tld']
        );

        if (!$phpVersion) {
            $phpVersion = Site::phpRcVersion($site ?: basename(getcwd()));
        }

        output(Brew::getPhpExecutablePath($phpVersion));

        return CommandAlias::SUCCESS;
    })->descriptions('Get the PHP executable path for a given site', [
        'site' => 'The site to get the PHP executable path for',
    ]);

    /**
     * Proxy commands through to an isolated site's version of PHP.
     */
    $app->command('php [--site=] [command]', function (OutputInterface $output, $command) {
        warning('It looks like you are running `cli/valet.php` directly; please use the `valet` script in the project root instead.');
    })->descriptions("Proxy PHP commands with isolated site's PHP executable", [
        'command' => "Command to run with isolated site's PHP executable",
        '--site' => 'Specify the site to use to get the PHP version (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * Proxy commands through to an isolated site's version of Composer.
     */
    $app->command('composer [--site=] [command]', function (OutputInterface $output, $command) {
        warning('It looks like you are running `cli/valet.php` directly; please use the `valet` script in the project root instead.');
    })->descriptions("Proxy Composer commands with isolated site's PHP executable", [
        'command' => "Composer command to run with isolated site's PHP executable",
        '--site' => 'Specify the site to use to get the PHP version (e.g. if the site isn\'t linked as its directory name)',
    ]);

    /**
     * Tail log file.
     */
    $app->command('log [-f|--follow] [-l|--lines=] [key]', function (OutputInterface $output, $follow, $lines, $key = null) {
        $defaultLogs = [
            'php-fpm' => BREW_PREFIX . '/var/log/php-fpm.log',
            'nginx' => VALET_HOME_PATH . '/Log/nginx-error.log',
        ];

        $configLogs = data_get(Configuration::read(), 'logs');
        if (!is_array($configLogs)) {
            $configLogs = [];
        }

        $logs = array_merge($defaultLogs, $configLogs);
        ksort($logs);

        if (!$key) {
            info(implode(PHP_EOL, [
                'In order to tail a log, pass the relevant log key (e.g. "nginx")',
                'along with any optional tail parameters (e.g. "-f" for follow).',
                null,
                'For example: "valet log nginx -f --lines=3"',
                null,
                'Here are the logs you might be interested in.',
                null,
            ]));

            table(
                ['Keys', 'Files'],
                collect($logs)->map(function ($file, $key) {
                    return [$key, $file];
                })->toArray()
            );

            info(implode(PHP_EOL, [
                null,
                'Tip: Set custom logs by adding a "logs" key/file object',
                'to your "' . Configuration::path() . '" file.',
            ]));

            return CommandAlias::SUCCESS;
        }

        if (!isset($logs[$key])) {
            warning('No logs found for [' . $key . '].');
            return CommandAlias::FAILURE;
        }

        $file = $logs[$key];
        if (!file_exists($file)) {
            warning('Log path [' . $file . '] does not (yet) exist.');
            return CommandAlias::FAILURE;
        }

        $options = [];
        if ($follow) {
            $options[] = '-f';
        }
        if ((int)$lines) {
            $options[] = '-n ' . (int)$lines;
        }

        $command = implode(' ', array_merge(['tail'], $options, [$file]));

        passthru($command);

        return CommandAlias::SUCCESS;
    })->descriptions('Tail log file');

    /**
     * Configure or display the directory-listing setting.
     */
    $app->command('directory-listing [status]', function (OutputInterface $output, $status = null) {
        $key = 'directory-listing';
        $config = Configuration::read();

        if (in_array($status, ['on', 'off'])) {
            $config[$key] = $status;
            Configuration::write($config);

            output('Directory listing setting is now: ' . $status);
            return CommandAlias::SUCCESS;
        }

        $current = $config[$key] ?? 'off';
        output('Directory listing is ' . $current);
        return CommandAlias::SUCCESS;
    })->descriptions('Determine directory-listing behavior. Default is off, which means a 404 will display.', [
        'status' => 'on or off. (default=off) will show a 404 page; [on] will display a listing if project folder exists but requested URI not found',
    ]);

    /**
     * Output diagnostics to aid in debugging Valet.
     */
    $app->command('diagnose [-p|--print] [--plain]', function (OutputInterface $output, $print, $plain) {
        Diagnose::run($print, $plain);

        info('Diagnostics output has been copied to your clipboard.');
    })->descriptions('Output diagnostics to aid in debugging Valet.', [
        '--print' => 'print diagnostics output while running',
        '--plain' => 'format clipboard output as plain text',
    ]);

    /**
     * List MySQL databases.
     *
     */
    $app->command('db:list', function (OutputInterface $output) {
        $databases = MySql::listDatabasesWithFullInfo();

        if (empty($databases)) {
            warning('No MySQL databases found.');
            return CommandAlias::SUCCESS;
        }

        $formattedDatabases = collect($databases)->map(function ($database) {
            return [
                'Database' => $database['Database'],
                'Tables' => abbreviate_number($database['Tables']),
                'Rows' => abbreviate_number($database['Rows']),
                'Size' => human_readable_size($database['Size'] ?? 0),
                'Collation' => $database['Collation'],
            ];
        })->sort()->values()->all();

        table(['Database', 'Tables', 'Rows', 'Size', 'Collation'], $formattedDatabases);

        return CommandAlias::SUCCESS;
    })->descriptions('List all MySQL databases');

    /**
     * Create a new MySQL database.
     */
    $app->command('db:create [database]', function (InputInterface $input, OutputInterface $output, $database) {
        MySql::createDatabase($database);
    })->descriptions('Create a new MySQL database', [
        'database' => 'The name of the database to create',
    ]);

    /**
     * Drop a MySQL database.
     */
    $app->command('db:drop [database] [-y|--yes]', function (InputInterface $input, OutputInterface $output, $database, $yes) {
        MySql::dropDatabase($database, $yes);
    })->descriptions('Drop a MySQL database', [
        'database' => 'The name of the database to drop',
        '--yes' => 'Skip the confirmation prompt',
    ]);

    /**
     * Reset database in MySQL.
     */
    $app->command('db:reset [database] [-y|--yes]', function (InputInterface $input, OutputInterface $output, $database, $yes) {
        MySql::resetDatabase($database);
    })->descriptions('Reset a MySQL database', [
        'database' => 'The name of the database to reset',
        '--yes' => 'Skip the confirmation prompt',
    ]);

    /**
     * Import a MySQL database.
     */
    $app->command('db:import [database] [file] [--force]', function (InputInterface $input, OutputInterface $output, $database, $file, $force) {
        MySql::importDatabase($database, $file, $force);
    })->descriptions('Import a MySQL database', [
        'database' => 'The name of the database to import to',
        'file' => 'The path to the SQL file to import',
    ]);

    /**
     * Export a MySQL database.
     */
    $app->command('db:export [database] [--sql]', function (InputInterface $input, OutputInterface $output, $database, $sql) {
        MySql::exportDatabase($database, $sql);
    })->descriptions('Export a MySQL database', [
        'database' => 'The name of the database to export',
        '--sql' => 'Export as SQL file',
    ]);

    /**
     * Configure valet database user for MySQL/MariaDB.
     */
    $app->command('db:configure [--force]', function (InputInterface $input, OutputInterface $output, $force) {
        MySql::configure($force);
    })->descriptions('Configure valet database user for MySQL');

    $app->command('test', function () {
        PhpMyAdmin::install();
    })->descriptions('Configure valet database user for MySQL');
}

return $app;
