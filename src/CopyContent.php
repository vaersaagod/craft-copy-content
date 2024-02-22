<?php

namespace vaersaagod\copycontent;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Plugin;
use craft\events\DefineHtmlEvent;
use craft\web\View;

use yii\base\Event;

/**
 * CopyContent plugin
 *
 * @method static CopyContent getInstance()
 */
class CopyContent extends Plugin
{
    public string $schemaVersion = '1.0.0';

    public function init(): void
    {
        parent::init();

        // Defer most setup tasks until Craft is fully initialized
        Craft::$app->onInit(function() {
            $this->attachEventHandlers();
            // ...
        });
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_ADDITIONAL_BUTTONS,
            function (DefineHtmlEvent $event) {
                /** @var ElementInterface $element */
                $element = $event->sender;
                if ($event->static || $element->getIsRevision()) {
                    return;
                }
                $user = Craft::$app->getUser()->getIdentity();
                if (!Craft::$app->getElements()->canCreateDrafts($element, $user)) {
                    return;
                }
                $event->html .= Craft::$app->getView()->renderTemplate(
                    '_copycontent/_copy-content-button.twig',
                    ['element' => $event->sender],
                    View::TEMPLATE_MODE_CP
                );
            }
        );
    }
}
