<?php

namespace Valet;

class Mailpit
{
    public function __construct(
        public Brew $brew,
        public Site $site,
        public Configuration $config
    ){}

    public function install(): void
    {
        if (!$this->brew->installed('mailpit')) {
            $this->brew->installOrFail('mailpit');
            $this->brew->restartService('mailpit');
            $tld = $this->config->read()['tld'];

            $this->site->proxyCreate(
                url: "mailpit.{$tld}",
                host: 'http://localhost:8025',
                secure: true
            );
        }
    }

    public function restart(): void
    {
        $this->brew->restartService('mailpit');
    }

    public function stop(): void
    {
        $this->brew->stopService('mailpit');
    }

    public function uninstall(): void
    {
        $this->brew->uninstallFormula('mailpit');
    }
}
