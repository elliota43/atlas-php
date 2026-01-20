<?php

namespace SchemaOps\Console;

use Symfony\Component\Console\Application as BaseApplication;
class Application extends BaseApplication
{
    public function __construct()
    {
        parent::__construct('SchemaOps', '0.1.0');


    }
}