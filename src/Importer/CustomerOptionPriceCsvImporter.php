<?php

declare(strict_types=1);

namespace Brille24\SyliusCustomerOptionsPlugin\Importer;

use Brille24\SyliusCustomerOptionsPlugin\Entity\ProductInterface;
use Brille24\SyliusCustomerOptionsPlugin\Reader\CsvReaderInterface;
use Brille24\SyliusCustomerOptionsPlugin\Updater\CustomerOptionPriceUpdaterInterface;
use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AdminUserInterface;
use Sylius\Component\Core\Repository\ProductRepositoryInterface;
use Sylius\Component\Mailer\Sender\SenderInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Webmozart\Assert\Assert;

class CustomerOptionPriceCsvImporter implements CustomerOptionPriceCsvImporterInterface
{
    protected const BATCH_SIZE = 100;

    private const REQUIRED_FIELDS = [
        'customer_option_code'       => true,
        'customer_option_value_code' => true,
        'channel_code'               => true,
        'valid_from'                 => false,
        'valid_to'                   => false,
        'type'                       => true,
        'amount'                     => true,
        'percent'                    => true,
        'product_code'               => true,
    ];

    /** @var CsvReaderInterface */
    private $csvReader;

    /** @var EntityManagerInterface */
    protected $entityManager;

    /** @var CustomerOptionPriceUpdaterInterface */
    protected $priceUpdater;

    /** @var SenderInterface */
    protected $sender;

    /** @var TokenStorageInterface */
    protected $tokenStorage;

    /** @var ProductRepositoryInterface */
    protected $productRepository;

    /** @var ProductInterface[] */
    protected $products = [];

    public function __construct(
        CsvReaderInterface $csvReader,
        CustomerOptionPriceUpdaterInterface $priceUpdater,
        EntityManagerInterface $entityManager,
        SenderInterface $sender,
        TokenStorageInterface $tokenStorage,
        ProductRepositoryInterface $productRepository
    ) {
        $this->csvReader         = $csvReader;
        $this->priceUpdater      = $priceUpdater;
        $this->entityManager     = $entityManager;
        $this->sender            = $sender;
        $this->tokenStorage      = $tokenStorage;
        $this->productRepository = $productRepository;
    }

    /** {@inheritdoc} */
    public function import(string $source): array
    {
        $csv = $this->csvReader->readCsv($source);

        // Handle updates
        $i      = 0;
        $failed = [];
        foreach ($csv as $lineNumber => $data) {
            if (!$this->csvReader->isRowValid($data, self::REQUIRED_FIELDS)) {
                $failed[$lineNumber] = ['data' => $data, 'message' => 'Data is invalid'];

                continue;
            }

            try {
                $product = $this->getProduct($data['product_code']);
                Assert::isInstanceOf(
                    $product,
                    ProductInterface::class,
                    sprintf('Product with code "%s" not found', $data['product_code'])
                );

                $price = $this->priceUpdater->updateForProduct(
                    $data['customer_option_code'],
                    $data['customer_option_value_code'],
                    $data['channel_code'],
                    $product,
                    $data['valid_from'],
                    $data['valid_to'],
                    $data['type'],
                    (int) $data['amount'],
                    (float) $data['percent']
                );

                // Add the value price to the product so we can use it in later validations.
                $product->addCustomerOptionValuePrice($price);

                $this->entityManager->persist($price);

                if (++$i % self::BATCH_SIZE === 0) {
                    $this->entityManager->flush();
                }
            } catch (\Throwable $exception) {
                $failed[$lineNumber] = ['data' => $data, 'message' => $exception->getMessage()];
            }
        }

        $this->entityManager->flush();

        $this->sendFailReport($failed);

        return ['imported' => $i, 'failed' => count($failed)];
    }

    private function sendFailReport(array $failed): void
    {
        if (0 === count($failed)) {
            return;
        }

        // Send mail about failed imports
        /** @var AdminUserInterface $user */
        $user  = $this->tokenStorage->getToken()->getUser();
        $email = $user->getEmail();

        $csvHeader = ['Line', 'Error'];
        foreach (array_keys(current($failed)['data']) as $key) {
            $csvHeader[] = $key;
        }
        $csvData = [
            implode(',', $csvHeader),
        ];

        foreach ($failed as $line => $error) {
            $csvData[] = sprintf('%s,%s,%s', $line, $error['message'], implode(',', $error['data']));
        }

        /** @var string $tmpPath */
        $tmpPath = tempnam(sys_get_temp_dir(), 'cop');
        $csvPath = $tmpPath.'.csv';

        rename($tmpPath, $csvPath);
        file_put_contents($csvPath, implode("\n", $csvData));

        $this->sender->send('brille24_failed_csv_price_import', [$email], ['failed' => $failed], [$csvPath]);
    }

    /**
     * @param string $code
     *
     * @return ProductInterface|null
     */
    private function getProduct(string $code): ?ProductInterface
    {
        if (!isset($this->products[$code])) {
            $this->products[$code] = $this->productRepository->findOneByCode($code);
        }

        return $this->products[$code];
    }
}
