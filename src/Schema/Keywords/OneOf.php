<?php

declare(strict_types=1);

namespace OpenAPIValidation\Schema\Keywords;

use cebe\openapi\spec\Schema as CebeSchema;
use OpenAPIValidation\Schema\BreadCrumb;
use OpenAPIValidation\Schema\Exception\InvalidSchema;
use OpenAPIValidation\Schema\Exception\KeywordMismatch;
use OpenAPIValidation\Schema\Exception\SchemaMismatch;
use OpenAPIValidation\Schema\SchemaValidator;
use Respect\Validation\Exceptions\ExceptionInterface;
use Respect\Validation\Validator;
use function sprintf;

class OneOf extends BaseKeyword
{
    /** @var int this can be Validator::VALIDATE_AS_REQUEST or Validator::VALIDATE_AS_RESPONSE */
    protected $validationDataType;
    /** @var BreadCrumb */
    protected $dataBreadCrumb;

    public function __construct(CebeSchema $parentSchema, int $type, BreadCrumb $breadCrumb)
    {
        parent::__construct($parentSchema);
        $this->validationDataType = $type;
        $this->dataBreadCrumb     = $breadCrumb;
    }

    /**
     * This keyword's value MUST be an array.  This array MUST have at least
     * one element.
     *
     * Inline or referenced schema MUST be of a Schema Object and not a standard JSON Schema.
     *
     * An instance validates successfully against this keyword if it
     * validates successfully against exactly one schema defined by this
     * keyword's value.
     *
     * @param mixed        $data
     * @param CebeSchema[] $oneOf
     *
     * @throws KeywordMismatch
     */
    public function validate($data, array $oneOf) : void
    {
        try {
            Validator::arrayVal()->assert($oneOf);
            Validator::each(Validator::instance(CebeSchema::class))->assert($oneOf);
        } catch (ExceptionInterface $e) {
            throw InvalidSchema::becauseDefensiveSchemaValidationFailed($e);
        }

        // Validate against all schemas
        $matchedCount    = 0;
        $schemaValidator = new SchemaValidator($this->validationDataType);
        foreach ($oneOf as $schema) {
            try {
                $schemaValidator->validate($data, $schema, $this->dataBreadCrumb);
                $matchedCount++;
            } catch (SchemaMismatch $e) {
                // that did not match... its ok
            }
        }

        if ($matchedCount !== 1) {
            throw KeywordMismatch::fromKeyword('oneOf', $data, sprintf('Data must match exactly one schema, but matched %d', $matchedCount));
        }
    }
}
