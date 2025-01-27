<?php

namespace Paysera\Bundle\RestBundle\Tests;

use Mockery;
use Mockery\MockInterface;
use Paysera\Bundle\RestBundle\ApiManager;
use Paysera\Bundle\RestBundle\Exception\ApiException;
use Paysera\Bundle\RestBundle\Listener\RestListener;
use Paysera\Bundle\RestBundle\Normalizer\NameAwareDenormalizerInterface;
use Paysera\Bundle\RestBundle\RestApi;
use Paysera\Bundle\RestBundle\Service\ExceptionLogger;
use Paysera\Bundle\RestBundle\Service\ParameterToEntityMapBuilder;
use Paysera\Bundle\RestBundle\Service\RequestApiResolver;
use Paysera\Bundle\RestBundle\Service\RequestLogger;
use Paysera\Component\Serializer\Exception\EncodingException;
use Paysera\Component\Serializer\Exception\InvalidDataException;
use Paysera\Component\Serializer\Factory\ContextAwareNormalizerFactory;
use Paysera\Component\Serializer\Validation\PropertiesAwareValidator;
use Paysera\Component\Serializer\Validation\PropertyPathConverterInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FilterControllerEvent;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Event\GetResponseForControllerResultEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Paysera\Component\Serializer\Entity\Violation;

/**
 * These tests use heavy object mocking, however it makes sure that as much code as possible is executed
 * These tests are used for refactoring RestListener
 */
class RestListenerTest extends TestCase
{
    /**
     * @var MockInterface|ApiManager
     */
    private $apiManager;

    /**
     * @var MockInterface|ContextAwareNormalizerFactory
     */
    private $normalizerFactory;

    /**
     * @var MockInterface|LoggerInterface
     */
    private $logger;

    /**
     * @var MockInterface|ParameterToEntityMapBuilder
     */
    private $parameterToEntityMapBuilder;

    /**
     * @var MockInterface|RequestLogger
     */
    private $requestLogger;

    /**
     * @var MockInterface|FilterControllerEvent
     */
    private $filterControllerEvent;

    /**
     * @var ExceptionLogger
     */
    private $exceptionLogger;

    /**
     * @var MockInterface|RequestApiResolver
     */
    protected $requestApiResolver;

    private $storedLoggerMessages = [];

    private $storedContext = [];

    public function setUp(): void
    {
        $this->apiManager = Mockery::mock(ApiManager::class);

        $this->normalizerFactory = Mockery::mock(ContextAwareNormalizerFactory::class);

        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('debug')->andReturnUsing($this->storeLoggerMessage());

        $this->parameterToEntityMapBuilder = Mockery::mock(ParameterToEntityMapBuilder::class);

        $this->requestLogger = Mockery::mock(RequestLogger::class);

        $this->filterControllerEvent = Mockery::mock(FilterControllerEvent::class);

        $this->exceptionLogger = Mockery::mock(ExceptionLogger::class);

        $this->requestApiResolver = Mockery::mock(RequestApiResolver::class);
    }

    public function testOnKernelControllerNoMappersOnlyParameterToEntityMap()
    {
        $parameterToEntityMap = ['key' => 'entity'];
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->filterControllerEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn($parameterToEntityMap);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->filterControllerEvent);
        $key = key($parameterToEntityMap);
        $this->assertEquals($parameterToEntityMap[$key], $parameterBag->get($key));
    }

    public function testOnKernelControllerWithMapperAndParameterToEntityMap()
    {
        $entity = [1];
        $parameterToEntityMap = ['key' => $entity];
        $name = 'requestName';
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->filterControllerEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getValidationGroups');
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn($parameterToEntityMap);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->filterControllerEvent);
        $key = key($parameterToEntityMap);
        $this->assertEquals($parameterToEntityMap[$key], $parameterBag->get($key));
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestMapperWhenDecodingFails()
    {
        $this->expectException(ApiException::class);
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent')->andReturn('a=b&c=d');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;


        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity');
        $requestMapper->shouldReceive('getName')->andReturn('name');

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->filterControllerEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getDecoder')->andThrow(EncodingException::class);
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups');
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->filterControllerEvent);
    }

    public function testOnKernelControllerWithRequestMapperWhenMappingFails()
    {
        $this->expectException(ApiException::class);
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andThrow(InvalidDataException::class);
        $requestMapper->shouldReceive('getName')->andReturn('name');

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->filterControllerEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups');
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->filterControllerEvent);
    }

    public function testOnKernelControllerWithRequestMapperWhenMappingSucceedsWithoutValidation()
    {
        $name = 'requestName';
        $entity = [1];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->filterControllerEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups');
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->filterControllerEvent);
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestMapperWhenMappingSucceedsWithValidation()
    {
        $name = 'requestName';
        $entity = [1];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->filterControllerEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([]);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->shouldReceive('validate')->andThrow(InvalidDataException::class);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->filterControllerEvent);
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestMapperValidationThrowsException()
    {
        $this->expectException(ApiException::class);
        $name = 'requestName';
        $entity = [1];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->filterControllerEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->shouldReceive('validate')->andThrow(InvalidDataException::class);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->filterControllerEvent);
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestQueryMapperWhenMappingFails()
    {
        $this->expectException(ApiException::class);
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;
        $queryParameterBag = new ParameterBag();
        $request->query = $queryParameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andThrow(InvalidDataException::class);
        $requestMapper->shouldReceive('getName')->andReturn('name');

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->filterControllerEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getRequestMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getValidationGroups');
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();
        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->filterControllerEvent);
    }

    public function testOnKernelControllerWithRequestQueryMapperValidationThrowsException()
    {
        $this->expectException(ApiException::class);
        $name = 'requestName';
        $entity = [1];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;
        $queryParameterBag = new ParameterBag();
        $request->query = $queryParameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->filterControllerEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getRequestMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->shouldReceive('validate')->andThrow(InvalidDataException::class);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->filterControllerEvent);
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestQueryMapperWhenMappingSucceedsWithValidation()
    {
        $name = 'requestName';
        $entity = [1];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;
        $queryParameterBag = new ParameterBag();
        $request->query = $queryParameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->filterControllerEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getRequestMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $propertiesAwareValidator = $this->createPropertiesAwareValidator();
        $propertiesAwareValidator->shouldReceive('validate');
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $restListener->onKernelController($this->filterControllerEvent);
        $this->assertEquals($entity, $parameterBag->get($name));
    }

    public function testOnKernelControllerWithRequestQueryMapperValidationThrowsExceptionWithPathConverter()
    {
        $name = 'requestName';
        $entity = [
            'firstName' => 1,
            'last_name' => 2,
        ];
        $this->logger->shouldReceive('notice')->andReturnUsing($this->storeLoggerMessage());
        $request = Mockery::mock(Request::class);
        $request->shouldReceive('getContent');
        $parameterBag = new ParameterBag();
        $parameterBag->set('_controller', 'controller');
        $request->attributes = $parameterBag;
        $queryParameterBag = new ParameterBag();
        $request->query = $queryParameterBag;

        $requestMapper = Mockery::mock(NameAwareDenormalizerInterface::class);
        $requestMapper->shouldReceive('mapToEntity')->andReturn($entity);
        $requestMapper->shouldReceive('getName')->andReturn($name);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn(null);

        $this->filterControllerEvent->shouldReceive('getRequest')->andReturn($request);
        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getSecurityStrategy')->andReturnNull();
        $this->apiManager->shouldReceive('getRequestQueryMapper')->andReturn($requestMapper);
        $this->apiManager->shouldReceive('getRequestMapper')->andReturnNull();
        $this->apiManager->shouldReceive('getValidationGroups')->andReturn([RestApi::DEFAULT_VALIDATION_GROUP]);
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $validator = Mockery::mock(ValidatorInterface::class);
        $violationList = new ConstraintViolationList([
            new ConstraintViolation('firstName message', '', [], '', 'firstName', '1'),
            new ConstraintViolation('lastName message', '', [], '', 'last_name', '2'),
        ]);
        $validator->shouldReceive('validate')->andReturn($violationList);
        $propertyPathConverter = Mockery::mock(PropertyPathConverterInterface::class);
        $propertyPathConverter->shouldReceive('convert')->andReturnUsing(function ($path) {
            return strtoupper($path);
        });
        $propertiesAwareValidator = new PropertiesAwareValidator($validator, $propertyPathConverter);
        $this->apiManager->shouldReceive('createPropertiesValidator')->andReturn($propertiesAwareValidator);

        $this->parameterToEntityMapBuilder->shouldReceive('buildParameterToEntityMap')->andReturn([]);

        $restListener = $this->createRestListener();

        $exceptionThrowed = false;
        try {
            $restListener->onKernelController($this->filterControllerEvent);
        } catch (ApiException $apiException) {
            $exceptionThrowed = true;
            $this->assertEquals(
                [
                    'FIRSTNAME' => ['firstName message'],
                    'LAST_NAME' => ['lastName message'],
                ],
                $apiException->getProperties()
            );

            $this->assertEquals(
                [
                    (new Violation())->setField('FIRSTNAME')->setMessage('firstName message'),
                    (new Violation())->setField('LAST_NAME')->setMessage('lastName message'),
                ],
                $apiException->getViolations()
            );
        }

        $this->assertTrue($exceptionThrowed);
        $this->assertNull($parameterBag->get($name));
    }

    public function testOnKernelViewResponseHasXFrameOptionsHeader()
    {
        $restListener = $this->createRestListener();

        $this->apiManager->shouldReceive('getLogger');
        $this->apiManager->shouldReceive('getCacheStrategy');
        $this->apiManager->shouldReceive('getRequestLoggingParts')->andReturnNull();

        $restApi = Mockery::mock(RestApi::class);

        $this->requestApiResolver->shouldReceive('getApiKeyForRequest');
        $this->requestApiResolver->shouldReceive('getApiForRequest')->andReturn($restApi);

        $httpKernelMock = Mockery::mock(HttpKernelInterface::class);
        $requestMock = Mockery::mock(Request::class);

        $event = new GetResponseForControllerResultEvent(
            $httpKernelMock,
            $requestMock,
            HttpKernelInterface::MASTER_REQUEST,
            null
        );

        $restListener->onKernelView($event);

        $responseHeaders = $event->getResponse()->headers;
        $headerName = 'x-frame-options';

        $this->assertTrue($responseHeaders->has($headerName));
        $this->assertEquals('DENY', $responseHeaders->get($headerName));
    }

    private function storeLoggerMessage()
    {
        return function($value, $context = null) {
            $this->storedLoggerMessages[] = $value;
            $this->storedContext[] = $context;
        };
    }

    /**
     * @return RestListener
     */
    private function createRestListener()
    {
        return new RestListener(
            $this->apiManager,
            $this->normalizerFactory,
            $this->logger,
            $this->parameterToEntityMapBuilder,
            $this->requestLogger,
            $this->exceptionLogger,
            $this->requestApiResolver,
            []
        );
    }

    private function createPropertiesAwareValidator()
    {
        return Mockery::mock(PropertiesAwareValidator::class);
    }
}
