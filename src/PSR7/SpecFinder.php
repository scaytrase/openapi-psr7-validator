<?php

declare(strict_types=1);

namespace OpenAPIValidation\PSR7;

use cebe\openapi\exceptions\TypeErrorException;
use cebe\openapi\spec\Header;
use cebe\openapi\spec\Header as HeaderSpec;
use cebe\openapi\spec\MediaType;
use cebe\openapi\spec\OpenApi;
use cebe\openapi\spec\Operation;
use cebe\openapi\spec\Parameter;
use cebe\openapi\spec\PathItem;
use cebe\openapi\spec\Reference;
use cebe\openapi\spec\Response as ResponseSpec;
use cebe\openapi\spec\SecurityRequirement;
use cebe\openapi\spec\SecurityScheme;
use OpenAPIValidation\PSR7\Exception\NoOperation;
use OpenAPIValidation\PSR7\Exception\NoPath;
use OpenAPIValidation\PSR7\Exception\NoResponseCode;
use OpenAPIValidation\Schema\Exception\InvalidSchema;
use function json_decode;
use function json_encode;
use function property_exists;

final class SpecFinder
{
    /** @var OpenApi */
    private $openApi;

    public function __construct(OpenApi $openApi)
    {
        $this->openApi = $openApi;
    }

    /**
     * Find a particular operation (path + method) in the spec
     *
     * @throws NoPath
     */
    public function findOperationSpec(OperationAddress $addr) : Operation
    {
        $pathSpec = $this->findPathSpec($addr);

        if (! isset($pathSpec->getOperations()[$addr->method()])) {
            throw NoOperation::fromPathAndMethod($addr->path(), $addr->method());
        }

        return $pathSpec->getOperations()[$addr->method()];
    }

    /**
     * Find a particular path in the spec
     *
     * @throws NoPath
     */
    public function findPathSpec(OperationAddress $addr) : PathItem
    {
        $pathSpec = $this->openApi->paths->getPath($addr->path());

        if (! $pathSpec) {
            throw NoPath::fromPath($addr->path());
        }

        return $pathSpec;
    }

    /**
     * @return Parameter[]
     *
     * @throws NoPath
     */
    public function findPathSpecs(OperationAddress $addr) : array
    {
        $spec = $this->findOperationSpec($addr);

        // 1. Collect operation-level params
        $pathSpecs = [];

        foreach ($spec->parameters as $p) {
            if ($p->in !== 'path') {
                continue;
            }

            $pathSpecs[$p->name] = $p;
        }

        // 2. Collect path-level params
        $pathSpec = $this->findPathSpec($addr);
        foreach ($pathSpec->parameters as $p) {
            if ($p->in !== 'path') {
                continue;
            }

            $pathSpecs += [$p->name => $p]; // union won't override
        }

        return $pathSpecs;
    }

    /**
     * @return Parameter[]
     *
     * @throws NoPath
     */
    public function findQuerySpecs(OperationAddress $addr) : array
    {
        $spec = $this->findOperationSpec($addr);

        // 1. Collect operation-level params
        $querySpecs = [];

        foreach ($spec->parameters as $p) {
            if ($p->in !== 'query') {
                continue;
            }

            $querySpecs[$p->name] = $p;
        }

        // 2. Collect path-level params
        $pathSpec = $this->findPathSpec($addr);
        foreach ($pathSpec->parameters as $p) {
            if ($p->in !== 'query') {
                continue;
            }

            $querySpecs += [$p->name => $p]; // union won't override
        }

        return $querySpecs;
    }

    /**
     * @return SecurityRequirement[]
     *
     * @throws NoPath
     */
    public function findSecuritySpecs(OperationAddress $addr) : array
    {
        $opSpec = $this->findOperationSpec($addr);

        // 1. Collect security params
        if (property_exists($opSpec->getSerializableData(), 'security')) {
            // security is set on operation level
            $securitySpecs = $opSpec->security;
        } else {
            // security is set on root level (fallback option)
            $securitySpecs = $this->openApi->security;
        }

        return $securitySpecs;
    }

    /**
     * @return SecurityScheme[]
     */
    public function findSecuritySchemesSpecs() : array
    {
        return $this->openApi->components ? $this->openApi->components->securitySchemes : [];
    }

    /**
     * @return MediaType[]|Reference[]
     *
     * @throws NoPath
     */
    public function findBodySpec(OperationAddress $addr) : array
    {
        if ($addr instanceof ResponseAddress) {
            return $this->findResponseSpec($addr)->content;
        }

        $requestBody = $this->findOperationSpec($addr)->requestBody;

        if (! $requestBody) {
            return [];
        }

        return $requestBody->content;
    }

    /**
     * Find the schema which describes a given response
     *
     * @throws NoPath
     */
    public function findResponseSpec(ResponseAddress $addr) : ResponseSpec
    {
        $operation = $this->findOperationSpec($addr);

        $response = $operation->responses->getResponse($addr->responseCode());

        if (! $response) {
            $response = $operation->responses->getResponse('default');
        }

        if (! $response) {
            throw NoResponseCode::fromPathAndMethodAndResponseCode(
                $addr->path(),
                $addr->method(),
                $addr->responseCode()
            );
        }

        return $response;
    }

    /**
     * @return Header[]
     *
     * @throws NoPath
     */
    public function findHeaderSpecs(OperationAddress $addr) : array
    {
        // Response headers are specified differently from request headers
        if ($addr instanceof ResponseAddress) {
            return $this->findResponseSpec($addr)->headers;
        }

        $spec = $this->findOperationSpec($addr);

        // 1. Collect operation level headers from "parameters" keyword
        // An API call may require that custom headers be sent with an HTTP request. OpenAPI lets you define custom request headers as in: header parameters.
        $headerSpecs = [];
        foreach ($spec->parameters as $p) {
            if ($p->in !== 'header') {
                continue;
            }

            $headerData = json_decode(json_encode($p->getSerializableData()), true);
            unset($headerData['in'], $headerData['name']);
            try {
                $headerSpecs[$p->name] = new HeaderSpec($headerData);
            } catch (TypeErrorException $e) {
                throw InvalidSchema::becauseDefensiveSchemaValidationFailed($e);
            }
        }

        // 2. Collect path-level headers from "parameters" keyword
        // Path level params are fall-backs
        $pathSpec = $this->findPathSpec($addr);
        foreach ($pathSpec->parameters as $p) {
            if ($p->in !== 'header') {
                continue;
            }

            $headerSpecs += [$p->name => $p]; // union won't override
        }

        return $headerSpecs;
    }

    /**
     * @return Parameter[]
     *
     * @throws NoPath
     */
    public function findCookieSpecs(OperationAddress $addr) : array
    {
        $spec = $this->findOperationSpec($addr);

        $cookieSpecs = [];

        // 1. Find operation level params
        foreach ($spec->parameters as $p) {
            if ($p->in !== 'cookie') {
                continue;
            }

            $cookieSpecs[$p->name] = $p;
        }

        // 2. Collect path-level params
        $pathSpec = $this->findPathSpec($addr);
        foreach ($pathSpec->parameters as $p) {
            if ($p->in !== 'cookie') {
                continue;
            }

            $cookieSpecs += [$p->name => $p]; // union won't override
        }

        return $cookieSpecs;
    }
}
