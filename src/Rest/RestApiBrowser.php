<?php

namespace Ubirak\RestApiBehatExtension\Rest;

use Http\Client\HttpClient;
use Http\Discovery\HttpClientDiscovery;
use Http\Discovery\MessageFactoryDiscovery;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Tolerance\Operation\Callback;
use Tolerance\Operation\Runner\RetryOperationRunner;
use Tolerance\Operation\Runner\CallbackOperationRunner;
use Tolerance\Waiter\SleepWaiter;
use Tolerance\Waiter\TimeOut;

class RestApiBrowser
{
    /** @var HttpClient */
    private $httpClient;

    /** @var RequestInterface */
    private $request;

    /** @var ResponseInterface */
    private $response;

    /** @var array */
    private $requestHeaders = [];

    /** @var array */
    private $requestFiles = [];

    /** @var ResponseStorage */
    private $responseStorage;

    /** @var string */
    private $host;

    /** @var MessageFactoryDiscovery */
    private $messageFactory;

    /**
     * @param string $host
     */
    public function __construct($host, HttpClient $httpClient = null)
    {
        $this->host = $host;
        $this->httpClient = $httpClient ?: HttpClientDiscovery::find();
        $this->messageFactory = MessageFactoryDiscovery::find();
    }

    /**
     * Allow to override the httpClient to use yours with specific middleware for example.
     */
    public function useHttpClient(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
    }

    /**
     * @param ResponseStorage $responseStorage
     */
    public function enableResponseStorage(ResponseStorage $responseStorage)
    {
        $this->responseStorage = $responseStorage;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param ResponseInterface $response
     */
    public function setResponse(ResponseInterface $response)
    {
        $this->response = $response;
    }

    public function getRequest()
    {
        return $this->request;
    }

    public function getRequestHeaders()
    {
        return $this->requestHeaders;
    }

    /**
     * @param string       $method
     * @param string       $uri
     * @param string|array $body
     */
    public function sendRequest($method, $uri, $body = null)
    {
        if (false === $this->hasHost($uri)) {
            $uri = rtrim($this->host, '/').'/'.ltrim($uri, '/');
        }

        if (is_array($body)) {
            $body = $this->buildMultipartBody($body);
        }

        $this->request = $this->messageFactory->createRequest($method, $uri, $this->requestHeaders, $body);
        $this->response = $this->httpClient->sendRequest($this->request);
        $this->requestHeaders = [];

        if (null !== $this->responseStorage) {
            $this->responseStorage->writeRawContent((string) $this->response->getBody());
        }
    }

    public function sendRequestUntil($method, $uri, $body, callable $assertion, $maxExecutionTime = 10)
    {
        $runner = new RetryOperationRunner(
            new CallbackOperationRunner(),
            new TimeOut(new SleepWaiter(), $maxExecutionTime)
        );
        $restApiBrowser = $this;
        $runner->run(new Callback(function () use ($restApiBrowser, $method, $uri, $body, $assertion) {
            $restApiBrowser->sendRequest($method, $uri, $body);

            return $assertion();
        }));
    }

    /**
     * @param string $name
     * @param string $value
     */
    public function setRequestHeader($name, $value)
    {
        $this->removeRequestHeader($name);
        $this->addRequestHeader($name, $value);
    }

    /**
     * @param string $name
     * @param string $value
     */
    public function addRequestHeader($name, $value)
    {
        $name = strtolower($name);
        if (isset($this->requestHeaders[$name])) {
            $this->requestHeaders[$name] .= ', '.$value;
        } else {
            $this->requestHeaders[$name] = $value;
        }
    }

    /**
     * @param string $headerName
     */
    private function removeRequestHeader($headerName)
    {
        $headerName = strtolower($headerName);
        if (array_key_exists($headerName, $this->requestHeaders)) {
            unset($this->requestHeaders[$headerName]);
        }
    }

    /**
     * @param string $name
     * @param string $path
     */
    public function addFileToRequest($name, $path)
    {
        $this->requestFiles[] = [
            'name' => $name,
            'path' => $path,
        ];
    }

    /**
     * @param array $body
     *
     * @return \GuzzleHttp\Psr7\MultipartStream
     */
    private function buildMultipartBody($body)
    {
        $multiparts = array_merge(
            array_map(
                function ($key, $value) {
                    return ['name' => $key, 'contents' => $value];
                },
                array_keys($body),
                $body
            ),
            array_map(
                function ($file) {
                    return ['name' => $file['name'], 'contents' => fopen($file['path'], 'r')];
                },
                $this->requestFiles
            )
        );

        $boundary = sha1(uniqid('', true));
        $this->setRequestHeader('Content-Type', 'multipart/form-data; boundary='.$boundary);

        return new \GuzzleHttp\Psr7\MultipartStream($multiparts, $boundary);
    }

    /**
     * @param string $uri
     *
     * @return bool
     */
    private function hasHost($uri)
    {
        return false !== strpos($uri, '://');
    }
}
