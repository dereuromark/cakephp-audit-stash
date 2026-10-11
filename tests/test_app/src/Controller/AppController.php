<?php

declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Controller\Controller;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Psr\Http\Message\UriInterface;

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

    /**
     * @param \Cake\Event\EventInterface $event Event
     *
     * @return mixed
     */
    public function beforeRender(EventInterface $event)
    {
        Configure::write('Test.hostBeforeRender', true);

        return parent::beforeRender($event);
    }

    /**
     * @param \Cake\Event\EventInterface $event Event
     *
     * @return mixed
     */
    public function afterFilter(EventInterface $event)
    {
        Configure::write('Test.hostAfterFilter', true);

        return parent::afterFilter($event);
    }

    /**
     * @param \Cake\Event\EventInterface $event Event
     * @param \Psr\Http\Message\UriInterface|array|string $url URL
     * @param \Cake\Http\Response $response Response
     *
     * @return mixed
     */
    public function beforeRedirect(EventInterface $event, UriInterface|array|string $url, Response $response)
    {
        Configure::write('Test.hostBeforeRedirect', true);

        return parent::beforeRedirect($event, $url, $response);
    }
}
