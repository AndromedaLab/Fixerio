<?php

namespace Fadion\Fixerio;

use DateTime;
use Fadion\Fixerio\Exceptions\ConnectionException;
use Fadion\Fixerio\Exceptions\ResponseException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\TransferException;

class Exchange
{
    /**
     * Guzzle client
     * @var GuzzleHttp\Client
     */
    private $guzzle;

    /**
     * URL of fixer.io
     * @var string
     */
    private $url = "data.fixer.io/api";

    /**
     * Date when an historical call is made
     * @var string
     */
    private $date;

    /**
     * Start date when timeseries call is made
     * @var ?string
     */
    private $startDate = null;

    /**
     * End date when timeseries call is made
     * @var ?string
     */
    private $endDate = null;

    /**
     * Http or Https
     * @var string
     */
    private $protocol = 'http';

    /**
     * Base currency
     * @var string
     */
    private $base = 'EUR';

    /**
     * List of currencies to return
     * @var array
     */
    private $symbols = [];

    /**
     * Holds whether the response should be
     * an object or not
     * @var array
     */
    private $asObject = false;

    /**
     * Holds the Fixer.io API key
     *
     * @var null|string
     */
    private $key = null;

    /**
     * @param $guzzle Guzzle client
     */
    public function __construct($guzzle = null)
    {
        if (isset($guzzle)) {
            $this->guzzle = $guzzle;
        } else {
            $this->guzzle = new GuzzleClient();
        }
    }

    /**
     * Sets the protocol to https
     *
     * @return Exchange
     */
    public function secure()
    {
        $this->protocol = 'https';

        return $this;
    }

    /**
     * Sets the base currency
     *
     * @param  string $currency
     * @return Exchange
     */
    public function base($currency)
    {
        $this->base = $currency;

        return $this;
    }

    /**
     * Sets the API key
     *
     * @param  string $key
     * @return Exchange
     */
    public function key($key)
    {
        $this->key = $key;

        return $this;
    }

    /**
     * Sets the currencies to return.
     * Expects either a list of arguments or
     * a single argument as array
     *
     * @param  array $currencies
     * @return Exchange
     */
    public function symbols($currencies = null)
    {
        if (func_num_args() and !is_array(func_get_args()[0])) {
            $currencies = func_get_args();
        }

        $this->symbols = $currencies;

        return $this;
    }

    /**
     * Defines that the api call should be
     * historical, meaning it will return rates
     * for any day since the selected date
     *
     * @param  string $date
     * @return Exchange
     */
    public function historical($date)
    {
        $this->date = date('Y-m-d', strtotime($date));

        return $this;
    }

    /**
     * Defines that the api call should be
     * time-series, meaning it will return rates
     * for all days in date range
     *
     * @param string $startDate
     * @param string $endDate
     * @return Exchange
     */
    public function timeseries($startDate, $endDate)
    {
        $this->startDate = date('Y-m-d', strtotime($startDate));
        $this->endDate = date('Y-m-d', strtotime($endDate));

        return $this;
    }

    /**
     * Returns the correctly formatted url
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->buildUrl($this->url);
    }

    /**
     * Makes the request and returns the response
     * with the rates.
     *
     * @throws ConnectionException if the request is incorrect or times out
     * @throws ResponseException if the response is malformed
     * @return array
     */
    public function get()
    {
        $url = $this->buildUrl($this->url);

        try {
            $response = $this->makeRequest($url);

            return $this->prepareResponse($response);
        }
        // The client needs to know only one exception, no
        // matter what exception is thrown by Guzzle
         catch (TransferException $e) {
            throw new ConnectionException($e->getMessage());
        }
    }

    /**
     * Makes the request and returns the response
     * with the rates, as a Result object
     *
     * @throws ConnectionException if the request is incorrect or times out
     * @throws ResponseException if the response is malformed
     * @return Result
     */
    public function getResult()
    {
        $url = $this->buildUrl($this->url);

        try {
            $response = $this->makeRequest($url);

            return $this->prepareResponseResult($response);
        }
        // The client needs to know only one exception, no
        // matter what exception is thrown by Guzzle
         catch (TransferException $e) {
            throw new ConnectionException($e->getMessage());
        }
    }

    /**
     * Alias of get() but returns an object
     * response.
     *
     * @throws ConnectionException if the request is incorrect or times out
     * @throws ResponseException if the response is malformed
     * @return object
     */
    public function getAsObject()
    {
        $this->asObject = true;

        return $this->get();
    }

    /**
     * Forms the correct url from the different parts
     *
     * @param  string $url
     * @return string
     */
    private function buildUrl($url)
    {
        $url = $this->protocol . '://' . $url . '/';

        if ($this->date) {
            $url .= $this->date;
        } elseif ($this->startDate !== null && $this->endDate !== null) {
            $url .= 'timeseries';
        } else {
            $url .= 'latest';
        }

        $url .= '?base=' . $this->base;

        if ($this->key) {
            $url .= '&access_key=' . $this->key;
        }

        $symbols = $this->symbols;
        if ($symbols) {
            $url .= '&symbols=' . implode(',', $symbols);
        }

        if ($this->startDate !== null && $this->endDate !== null) {
            $url .= '&start_date=' . $this->startDate;
            $url .= '&end_date=' . $this->endDate;
        }

        return $url;
    }

    /**
     * Makes the http request
     *
     * @param  string $url
     * @return string
     */
    private function makeRequest($url)
    {
        $response = $this->guzzle->request('GET', $url);

        return $response->getBody();
    }

    /**
     * @param  string $body
     * @throws ResponseException if the response is malformed
     * @return array|\stdClass
     */
    private function prepareResponse($body)
    {
        $response = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ResponseException(json_last_error_msg());
        }

        if ($response['success'] === false) {
            $errorCode = isset($response['error']['code']) ? (int)$response['error']['code'] : 0;

            throw new ResponseException($this->getErrorMessageByCode($errorCode), $errorCode);
        }

        if (!is_array($response['rates'])) {
            throw new ResponseException('Response body is malformed.');
        }

        if ($this->asObject) {
            return (object) $response['rates'];
        }

        return $response['rates'];
    }

    /**
     * @param  string $body
     * @throws ResponseException if the response is malformed
     * @return Result
     */
    private function prepareResponseResult($body)
    {
        $response = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new ResponseException(json_last_error_msg());
        }

        if ($response['success'] === false) {
            throw new ResponseException($response['error']['info'], $response['error']['code']);
        }

        if (isset($response['rates']) and is_array($response['rates'])
            and isset($response['base']) and isset($response['date'])) {
            return new Result(
                $response['base'],
                new DateTime($response['date']),
                $response['rates']
            );
        }

        throw new ResponseException('Response body is malformed.');
    }

    /**
     * @param int $code
     *
     * @return string
     */
    private function getErrorMessageByCode($code)
    {
        $errorMessageByCode = [
            404 => 'The requested resource does not exist.',
            101 => 'No API Key was specified or an invalid API Key was specified.',
            103 => 'The requested API endpoint does not exist.',
            104 => 'The maximum allowed API amount of monthly API requests has been reached.',
            105 => 'The current subscription plan does not support this API endpoint.',
            106 => 'The current request did not return any results.',
            102 => 'The account this API request is coming from is inactive.',
            201 => 'An invalid base currency has been entered.',
            202 => 'One or more invalid symbols have been specified.',
            301 => 'No date has been specified.',
            302 => 'An invalid date has been specified.',
            403 => 'No or an invalid amount has been specified.',
            501 => 'No or an invalid timeframe has been specified.',
            502 => 'No or an invalid "start_date" has been specified.',
            503 => 'No or an invalid "end_date" has been specified.',
            504 => 'An invalid timeframe has been specified.',
            505 => 'The specified timeframe is too long, exceeding 365 days.',
        ];

        return isset($errorMessageByCode[$code]) ? $errorMessageByCode[$code] : 'Unknown error.';
    }

}
