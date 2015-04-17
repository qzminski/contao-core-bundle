<?php

/**
 * This file is part of Contao.
 *
 * Copyright (c) 2005-2015 Leo Feyer
 *
 * @license LGPL-3.0+
 */

namespace Contao\CoreBundle\Test\EventListener\Hook;

use Contao\CoreBundle\Event\TemplateEvent;
use Contao\CoreBundle\EventListener\Hook\ModifyFrontendPageListener;
use Contao\CoreBundle\Test\TestCase;
use Contao\FrontendTemplate;

/**
 * Tests the ModifyFrontendPageListener class.
 *
 * @author Leo Feyer <https:/github.com/leofeyer>
 */
class ModifyFrontendPageListenerTest extends TestCase
{
    /**
     * @var ModifyFrontendPageListener
     */
    private $listener;

    /**
     * Tests the object instantiation.
     */
    protected function setUp()
    {
        parent::setUp();

        $this->listener = new ModifyFrontendPageListener();
    }

    /**
     * Tests the object instantiation.
     */
    public function testInstantiation()
    {
        $this->assertInstanceOf('Contao\\CoreBundle\\EventListener\\Hook\\ModifyFrontendPageListener', $this->listener);
    }

    /**
     * Tests the onModifyFrontendPage() method.
     */
    public function testOnModifyFrontendPage()
    {
        $buffer   = 'foo';
        $key      = 'bar';
        $template = new FrontendTemplate();
        $event    = new TemplateEvent($buffer, $key, $template);

        $GLOBALS['TL_HOOKS']['modifyFrontendPage'][] = function ($buffer) {
            return $buffer;
        };

        $this->listener->onModifyFrontendPage($event);

        $this->assertEquals($buffer, $event->getBuffer());
        $this->assertEquals($key, $event->getKey());
        $this->assertEquals($template, $event->getTemplate());

        unset($GLOBALS['TL_HOOKS']);
    }

    /**
     * Tests passing arguments by reference.
     */
    public function testPassingArgumentsByReference()
    {
        $buffer    = 'foo';
        $key       = 'bar';
        $template  = new FrontendTemplate();
        $event     = new TemplateEvent($buffer, $key, $template);
        $template2 = new FrontendTemplate();

        $GLOBALS['TL_HOOKS']['modifyFrontendPage'][] = function ($buffer, &$key, &$template) use ($template2) {
            $key      = 'changed';
            $template = $template2;
        };

        $this->listener->onModifyFrontendPage($event);

        $this->assertEquals('', $event->getBuffer());
        $this->assertEquals('changed', $event->getKey());
        $this->assertEquals($template2, $event->getTemplate());

        unset($GLOBALS['TL_HOOKS']);
    }
}
