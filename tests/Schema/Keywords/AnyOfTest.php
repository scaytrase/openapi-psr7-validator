<?php

declare(strict_types=1);

namespace OpenAPIValidationTests\Schema\Keywords;

use OpenAPIValidation\Schema\Exception\KeywordMismatch;
use OpenAPIValidation\Schema\SchemaValidator;
use OpenAPIValidationTests\Schema\SchemaValidatorTest;

final class AnyOfTest extends SchemaValidatorTest
{
    public function testItValidatesAnyOfGreen() : void
    {
        $spec = <<<SPEC
schema:
  anyOf:
    - type: object
      properties:
        name:
          type: string
      required:
      - name
    - type: object
      properties:
        age:
          type: integer
      required:
      - age
SPEC;

        $schema = $this->loadRawSchema($spec);
        $data   = ['age' => 10];

        (new SchemaValidator())->validate($data, $schema);
        $this->addToAssertionCount(1);
    }

    public function testItValidatesAnyOfRed() : void
    {
        $spec = <<<SPEC
schema:
  anyOf:
    - type: object
      properties:
        name:
          type: string
      required:
      - name
    - type: object
      properties:
        age:
          type: integer
      required:
      - age
SPEC;

        $schema = $this->loadRawSchema($spec);
        $data   = ['time' => 'today'];

        try {
            (new SchemaValidator())->validate($data, $schema);
        } catch (KeywordMismatch $e) {
            $this->assertEquals('anyOf', $e->keyword());
        }
    }
}
