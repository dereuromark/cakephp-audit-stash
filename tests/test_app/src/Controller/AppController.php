<?php

declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\EventInterface;

class AppController extends Controller
{
    public function initialize(): void
    {
        parent::initialize();
        Configure::write('Test.hostInitialize', true);
    }

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        Configure::write('Test.hostBeforeFilter', true);
    }
}
