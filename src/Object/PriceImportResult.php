<?php

declare(strict_types=1);

namespace Brille24\SyliusCustomerOptionsPlugin\Object;

class PriceImportResult
{
    private int $imported;
    private int $failed;
    private array $errors;

    public function __construct(int $imported, int $failed, array $errors)
    {
        $this->imported = $imported;
        $this->failed   = $failed;
        $this->errors   = $errors;
    }

    /**
     * @return int
     */
    public function getImported(): int
    {
        return $this->imported;
    }

    /**
     * @return int
     */
    public function getFailed(): int
    {
        return $this->failed;
    }

    /**
     * @return array
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
