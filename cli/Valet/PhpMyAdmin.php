<?php

namespace Valet;

class PhpMyAdmin
{
    public function __construct(
        public Brew          $brew,
        public Site          $site,
    )
    {
    }

    public function install(): void
    {
        $this->brew->ensureInstalled('phpmyadmin');

        $path = "/opt/homebrew/share/phpmyadmin";
        $name = basename($path);

        $this->site->link($path, $name);

        $url = $this->site->domain($name);

        $this->site->secure($url, null, 368);

        $this->brew->restartService($this->brew->nginxServiceName());
    }

    public function uninstall(): void
    {
        $this->brew->stopService('phpmyadmin');
        $this->brew->uninstallFormula('phpmyadmin');
    }
}
