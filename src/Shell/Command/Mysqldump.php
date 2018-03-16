<?php

namespace Meanbee\Magedbm2\Shell\Command;

class Mysqldump extends Base
{
    public function __construct()
    {
        $this->arguments([
            '--single-transaction',
            '--quick'
        ]);
    }

    protected function name(): string
    {
        return 'mysqldump';
    }
}
