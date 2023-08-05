<?php

namespace vaersaagod\copycontent\controllers;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\behaviors\DraftBehavior;
use craft\fieldlayoutelements\BaseNativeField;
use craft\fields\Matrix;
use craft\web\Controller;

use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Copy Field Values From Site controller
 */
class DefaultController extends Controller
{

    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @return Response
     * @throws \Throwable
     * @throws \craft\errors\InvalidFieldException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\db\Exception
     * @throws \yii\web\BadRequestHttpException
     */
    public function actionIndex(): Response|string|null
    {

        $this->requireAcceptsJson();
        $this->requirePostRequest();

        $elementId = (int)$this->request->getRequiredBodyParam('elementId');
        $copyFromSiteId = (int)$this->request->getRequiredBodyParam('fromSiteId');
        $copyToSiteId = (int)$this->request->getRequiredBodyParam('toSiteId');

        Craft::$app->getSites()->setCurrentSite($copyToSiteId);

        $element = Craft::$app->getElements()->getElementById($elementId);

        if (!$element || $element->getIsRevision()) {
            return $this->asFailure(
                message: 'Invalid element ID'
            );
        }

        $user = Craft::$app->getUser()->getIdentity();

        if (!Craft::$app->getElements()->canCreateDrafts($element, $user)) {
            throw new ForbiddenHttpException('User can\'t create drafts for this element.');
        }

        $fromElement = Craft::$app->getElements()->getElementById($element->getCanonicalId(), get_class($element), $copyFromSiteId);

        if (!$fromElement) {
            return $this->asFailure(message: Craft::t('_copycontent', 'Couldn\'t find this {type} on the site you selected.', [
                'type' => $element::lowerDisplayName(),
            ]));
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {

            // Create a draft if need be
            if (!$element->getIsDraft()) {
                /** @var Element|DraftBehavior $element */
                $draft = Craft::$app->getDrafts()->createDraft($element, $user->id, null, null, [], true);
                $draft->setCanonical($element);
                $element = $draft;
            }

            $contentChanged = false;

            // Copy native + custom fields
            $fieldHandles = array_filter(array_values([
                ...array_map(static fn (BaseNativeField $nativeField) => $nativeField->attribute, $element->getFieldLayout()?->getAvailableNativeFields() ?? []),
                ...array_map(static fn (Field $customField) => $customField->handle, $element->getFieldLayout()?->getCustomFields() ?? []),
            ]));
            foreach ($fieldHandles as $fieldHandle) {
                $changed = $this->_copyFieldValueByHandle($element, $fromElement, $fieldHandle);
                $contentChanged = $contentChanged ?: $changed;
            }

            // Bail if no content was actually changed
            if (!$contentChanged) {
                $transaction->rollBack();
                return $this->asFailure(
                    message: Craft::t('_copycontent', 'Nothing to copy.')
                );
            }

            if (!Craft::$app->getElements()->saveElement($element, true, false, false)) {
                return $this->asFailure(
                    message: Craft::t('_copycontent', 'Couldn\'t copy content.')
                );
            }

            $transaction->commit();

            $successMessage = Craft::t('_copycontent', 'Content copied.');

            $this->setSuccessFlash($successMessage);

            Craft::$app->getSession()->broadcastToJs([
                'event' => 'saveElement',
                'id' => $element->id,
            ]);

            return $this->asSuccess(
                message: $successMessage
            );

        } catch (\Throwable $e) {

            $transaction->rollBack();

            return $this->asFailure(message: $e->getMessage());

        }
    }

    /**
     * @param ElementInterface $toElement
     * @param ElementInterface $fromElement
     * @param string $fieldHandle
     * @return bool
     * @throws \craft\errors\InvalidFieldException
     * @throws \yii\base\InvalidConfigException
     */
    private function _copyFieldValueByHandle(ElementInterface $toElement, ElementInterface $fromElement, string $fieldHandle): bool
    {

        $nativeFieldAttributes = array_map(static fn (BaseNativeField $nativeField) => $nativeField->attribute, $toElement->getFieldLayout()?->getAvailableNativeFields() ?? []);

        if (in_array($fieldHandle, $nativeFieldAttributes, true) && $toElement->{$fieldHandle} !== $fromElement->{$fieldHandle}) {
            $toElement->{$fieldHandle} = $fromElement->{$fieldHandle};
            return true;
        } else if ($field = Craft::$app->fields->getFieldByHandle($fieldHandle)) {
            $originalValue = $field->serializeValue($toElement->getFieldValue($fieldHandle), $toElement);
            $copiedValue = $field->serializeValue($fromElement->getFieldValue($fieldHandle), $fromElement);
            $toElement->setFieldValue($fieldHandle, $copiedValue);
            if ($field instanceof Matrix) {
                if (is_array($originalValue)) {
                    $originalValue = array_values($originalValue);
                }
                if (is_array($copiedValue)) {
                    $copiedValue = array_values($copiedValue);
                }
            }
            return $originalValue !== $copiedValue;
        }

        return false;
    }

}
