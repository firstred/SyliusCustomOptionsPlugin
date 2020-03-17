<?php

declare(strict_types=1);

namespace Brille24\SyliusCustomerOptionsPlugin\Importer;

use Brille24\SyliusCustomerOptionsPlugin\Entity\CustomerOptions\CustomerOptionValuePriceInterface;
use Brille24\SyliusCustomerOptionsPlugin\Entity\ProductInterface;
use Brille24\SyliusCustomerOptionsPlugin\Updater\CustomerOptionPriceUpdaterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Webmozart\Assert\Assert;

class CustomerOptionPriceByExampleImporter implements CustomerOptionPriceByExampleImporterInterface
{
    protected const BATCH_SIZE = 100;

    /** @var CustomerOptionPriceUpdaterInterface */
    protected $priceUpdater;

    /** @var EntityManagerInterface */
    protected $entityManager;

    /** @var SenderInterface */
    protected $sender;

    /** @var TokenStorageInterface */
    protected $tokenStorage;

    /** @var ProductRepositoryInterface */
    protected $productRepository;

    public function __construct(
        CustomerOptionPriceUpdaterInterface $priceUpdater,
        EntityManagerInterface $entityManager,
        SenderInterface $sender,
        TokenStorageInterface $tokenStorage,
        ProductRepositoryInterface $productRepository
    ) {
        $this->priceUpdater      = $priceUpdater;
        $this->entityManager     = $entityManager;
        $this->sender            = $sender;
        $this->tokenStorage      = $tokenStorage;
        $this->productRepository = $productRepository;
    }

    /** {@inheritdoc} */
    public function importForProducts(array $productCodes, CustomerOptionValuePriceInterface $examplePrice): array
    {
        $dateFrom = null;
        $dateTo   = null;
        if ($examplePrice->getDateValid() !== null) {
            $dateFrom = $examplePrice->getDateValid()->getStart()->format(DATE_ATOM);
            $dateTo   = $examplePrice->getDateValid()->getEnd()->format(DATE_ATOM);
        }

        $customerOptionCode      = $examplePrice->getCustomerOptionValue()->getCustomerOption()->getCode();
        $customerOptionValueCode = $examplePrice->getCustomerOptionValue()->getCode();
        $channelCode             = $examplePrice->getChannel()->getCode();

        $type    = $examplePrice->getType();
        $amount  = $examplePrice->getAmount();
        $percent = $examplePrice->getPercent();

        $failed = [];
        $i      = 0;
        foreach ($productCodes as $productCode) {
            try {
                /** @var ProductInterface|null $product */
                $product = $this->productRepository->findOneByCode($productCode);
                Assert::isInstanceOf(
                    $product,
                    ProductInterface::class,
                    sprintf('Product with code "%s" not found', $productCode)
                );

                $price = $this->priceUpdater->updateForProduct(
                    $customerOptionCode,
                    $customerOptionValueCode,
                    $channelCode,
                    $product,
                    $dateFrom,
                    $dateTo,
                    $type,
                    $amount,
                    $percent
                );

                $this->entityManager->persist($price);

                if (++$i % self::BATCH_SIZE === 0) {
                    $this->entityManager->flush();
                }
            } catch (\Throwable $exception) {
                $failed[$productCode] = $exception->getMessage();
            }
        }

        $this->entityManager->flush();

        $this->sendFailReport($failed);

        return ['imported' => $i, 'failed' => count($failed)];
    }

    /**
     * @param array $failed
     */
    protected function sendFailReport(array $failed): void
    {
        if (0 === count($failed)) {
            return;
        }

        // Send mail about failed imports
        /** @var AdminUserInterface $user */
        $user  = $this->tokenStorage->getToken()->getUser();
        $email = $user->getEmail();

        $this->sender->send('brille24_failed_price_by_example_import', [$email], ['failed' => $failed]);
    }
}
