<?php

declare(strict_types=1);

namespace Brille24\SyliusCustomerOptionsPlugin\Services;

use Brille24\SyliusCustomerOptionsPlugin\Enumerations\CustomerOptionTypeEnum;
use Brille24\SyliusCustomerOptionsPlugin\Validator\Constraints\ConditionalConstraint;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

class ConstraintCreator
{
    /**
     * Gets the value from the Customer Option value configuration
     *
     * @param array  $configuration
     * @param string $key
     *
     * @return mixed
     */
    public static function getValueFromConfiguration(array $configuration, string $key)
    {
        if (!array_key_exists($key, $configuration)) {
            return null;
        }

        return $configuration[$key]['value'];
    }

    /**
     * Creates a constraint form the configuration based on the type of Custom Option
     *
     * @param string $type
     * @param array  $configuration
     *
     * @return Constraint|null
     */
    public static function createFromConfiguration(string $type, array $configuration): ?Constraint
    {
        $getFromConfiguration = fn ($key) => self::getValueFromConfiguration($configuration, $key);

        switch ($type) {
            case CustomerOptionTypeEnum::TEXT:
                $lengthRange = [
                    'min' => $getFromConfiguration('brille24.form.config.min.length'),
                    'max' => $getFromConfiguration('brille24.form.config.max.length'),
                ];

                return new Length($lengthRange);
            case CustomerOptionTypeEnum::FILE:
                $allowedFileTypes = explode(',', (string) $getFromConfiguration('brille24.form.config.allowed_types'));

                return new File([
                    'maxSize'   => $getFromConfiguration('brille24.form.config.max.file_size'),
                    'mimeTypes' => array_map('trim', $allowedFileTypes),
                ]);
            case CustomerOptionTypeEnum::DATE:
            case CustomerOptionTypeEnum::DATETIME:
                $dateRange = [
                    'min' => $getFromConfiguration('brille24.form.config.min.date')['date'] ?? null,
                    'max' => $getFromConfiguration('brille24.form.config.max.date')['date'] ?? null,
                ];

                return new Range($dateRange);
            case CustomerOptionTypeEnum::NUMBER:
                $numberRange = [
                    'min' => $getFromConfiguration('brille24.form.config.min.number'),
                    'max' => $getFromConfiguration('brille24.form.config.max.number'),
                ];

                return new Range($numberRange);
        }

        return null;
    }

    public static function createConditionalConstraint(array $conditions, array $constraints): Constraint
    {
        return new ConditionalConstraint([
            'conditions'  => $conditions,
            'constraints' => $constraints,
        ]);
    }

    public static function createRequiredConstraint(): Constraint
    {
        return new NotBlank();
    }
}
