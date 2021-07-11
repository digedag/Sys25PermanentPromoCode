<?php

declare(strict_types = 1);
namespace Sys25\PermanentPromoCode\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionIndividualCode\PromotionIndividualCodeEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Cart\Event\CheckoutOrderPlacedEvent;
use Shopware\Core\Checkout\Promotion\Cart\PromotionProcessor;
use Shopware\Core\Checkout\Promotion\Aggregate\PromotionIndividualCode\PromotionIndividualCodeCollection;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Exception\InconsistentCriteriaIdsException;

class PromoCodeSubscriber implements EventSubscriberInterface
{

    /**
     *
     * @var EntityRepositoryInterface
     */
    private $codesRepository;

    public function __construct(EntityRepositoryInterface $codesRepository)
    {
        $this->codesRepository = $codesRepository;
    }

    /**
     * Returns an array of events this subscriber wants to listen to.
     *
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            CheckoutOrderPlacedEvent::class => 'onOrderPlaced'
        ];
    }

    /**
     *
     * @throws InconsistentCriteriaIdsException
     */
    public function onOrderPlaced(CheckoutOrderPlacedEvent $event): void
    {
        foreach ($event->getOrder()->getLineItems() as $item) {
            // only update promotions in here
            if ($item->getType() !== PromotionProcessor::LINE_ITEM_TYPE) {
                continue;
            }
            /** @var string $code */
            $code = $item->getPayload()['code'];

            $individualCode = $this->getIndividualCode($code, $event->getContext());

            // if we did not use an individual code we might have
            // just used a global one or anything else, so just quit in this case.
            if ($individualCode === null || ! ($individualCode instanceof PromotionIndividualCodeEntity)) {
                return;
            }

            // destroy used code...
            $this->codesRepository->update([
                [
                    'id' => $individualCode->getId(),
                    'code' => sprintf('%s_%s', $code, bin2hex(random_bytes(16)))
                ]
            ], $event->getContext());
            // ...and recreate a new one
            $data = [
                'promotionId' => $individualCode->getPromotionId(),
                'code' => $code
            ];
            $this->codesRepository->upsert([
                $data
            ], $event->getContext());
        }
    }

    /**
     * Gets all individual code entities for the provided code value.
     *
     * @throws InconsistentCriteriaIdsException
     */
    private function getIndividualCode(string $code, Context $context): ?PromotionIndividualCodeEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('code', $code));

        /** @var PromotionIndividualCodeCollection $result */
        $result = $this->codesRepository->search($criteria, $context)->getEntities();

        if (\count($result->getElements()) <= 0) {
            return null;
        }

        // return first element
        return $result->first();
    }
}
