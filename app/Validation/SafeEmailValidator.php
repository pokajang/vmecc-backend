<?php

namespace App\Validation;

use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\DNSCheckValidation;
use Egulias\EmailValidator\Validation\Extra\SpoofCheckValidation;
use Egulias\EmailValidator\Validation\MultipleValidationWithAnd;
use Egulias\EmailValidator\Validation\NoRFCWarningsValidation;
use Egulias\EmailValidator\Validation\RFCValidation;
use Illuminate\Container\Container;
use Illuminate\Validation\Concerns\FilterEmailValidation;
use Illuminate\Validation\Validator;

class SafeEmailValidator extends Validator
{
    public function validateEmail($attribute, $value, $parameters): bool
    {
        if (! is_string($value) && ! (is_object($value) && method_exists($value, '__toString'))) {
            return false;
        }

        $value = (string) $value;
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            return false;
        }

        $validations = collect($parameters)
            ->unique()
            ->map(fn ($validation) => match (true) {
                $validation === 'strict' => new NoRFCWarningsValidation(),
                $validation === 'dns' => new DNSCheckValidation(),
                $validation === 'spoof' => new SpoofCheckValidation(),
                $validation === 'filter' => new FilterEmailValidation(),
                $validation === 'filter_unicode' => FilterEmailValidation::unicode(),
                is_string($validation) && class_exists($validation) => $this->container->make($validation),
                default => new RFCValidation(),
            })
            ->values()
            ->all() ?: [new RFCValidation()];

        $emailValidator = Container::getInstance()->make(EmailValidator::class);

        return $emailValidator->isValid($value, new MultipleValidationWithAnd($validations));
    }
}
