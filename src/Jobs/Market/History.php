<?php

/*
 * This file is part of SeAT
 *
 * Copyright (C) 2015 to present Leon Jacobs
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
 */

namespace Seat\Eveapi\Jobs\Market;

use Illuminate\Bus\Batchable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Seat\Eseye\Exceptions\RequestFailedException;
use Seat\Eveapi\Exception\TemporaryEsiOutageException;
use Seat\Eveapi\Jobs\EsiBase;
use Seat\Eveapi\Models\Market\Price;

/**
 * Class History.
 *
 * @package Seat\Eveapi\Jobs\Market
 */
class History extends EsiBase
{
    use Batchable;

    const THE_FORGE = 10000002;

    const TYPE_ID_BAN_CACHE_KEY_PREFIX = 'market_history_type_id_ban';
    const TYPE_ID_BAN_DURATION = 60 * 60 * 24 * 30; // 1 month

    // override the default from AbstractJob
    public const JOB_EXECUTION_TIMEOUT = 60 * 60 * 24; // 1 day

    /**
     * Describes how long the rate limit window lasts in seconds before resetting.
     *
     * @var int
     */
    const ENDPOINT_RATE_LIMIT_WINDOW = 60;

    /**
     * Describes how many calls can be made in the timespan described in ENDPOINT_RATE_LIMIT_WINDOW.
     *
     * @var int
     */
    const ENDPOINT_RATE_LIMIT_CALLS = 100;

    /**
     * @var string
     */
    protected $method = 'get';

    /**
     * @var string
     */
    protected $endpoint = '/markets/{region_id}/history/';

    /**
     * @var string
     */
    protected $version = 'v1';

    /**
     * @var array
     */
    protected $tags = ['public', 'market'];

    /**
     * Override base attempts limit and allow the job to be retried up to 100 times.
     * This value is tied to the amount of requests which should be done against ESI
     * in order to process a complete batch.
     *
     * Everytime a TemporaryOutageException is thrown, we will release the job back
     * in the queue. Every release is counting as an attempts.
     *
     * @var int
     */
    public $tries = 100;

    /**
     * @var array
     */
    private $type_ids;

    /**
     * History constructor.
     *
     * @param  array  $type_ids
     */
    public function __construct(array $type_ids)
    {
        parent::__construct();

        $this->type_ids = $type_ids;
    }

    /**
     * Add a tag to the job specifying the number of jobs related to the same batch chain.
     *
     * @param  $count
     * @return \Seat\Eveapi\Jobs\Market\History
     */
    public function setTotalBatchCount($count)
    {
        $this->tags = array_merge($this->tags, ['batch_size:' . $count]);

        return $this;
    }

    /**
     * Add a tag to the job specifying the current job number in the overall batch chain.
     *
     * @param  $current
     * @return \Seat\Eveapi\Jobs\Market\History
     */
    public function setCurrentBatchCount($current)
    {
        $this->tags = array_merge($this->tags, ['batch_current:' . $current]);

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @throws \Seat\Services\Exceptions\SettingException
     * @throws \Throwable
     */
    public function handle()
    {
        if ($this->batch() && $this->batch()->cancelled()) {
            logger()->debug(sprintf('[Jobs][%s] Orders - Cancelling job due to relevant batch %s cancellation.', $this->job->getJobId(), $this->batch()->id));

            return;
        }

        $region_id = setting('market_prices_region_id', true) ?: self::THE_FORGE;

        while(count($this->type_ids) > 0) {
            $delay = Redis::throttle('market-history-throttle')->block(0)->allow(self::ENDPOINT_RATE_LIMIT_CALLS)->every(self::ENDPOINT_RATE_LIMIT_WINDOW)->then(function () use ($region_id) {
                logger()->debug(sprintf('[Jobs][%s] History processing -> Remaining types: %d.', $this->job->getJobId(), count($this->type_ids)));

                $type_id = array_shift($this->type_ids);

                // skip type if it has been banned
                if(Cache::has(sprintf('%s.%d',self::TYPE_ID_BAN_CACHE_KEY_PREFIX, $type_id))) {
                    logger()->debug(sprintf('[Jobs][%s] History -> SKipping %d due to it having failed previously', $this->job->getJobId(), $type_id));
                    return false;
                }

                $this->query_string = [
                    'type_id' => $type_id,
                ];

                try {
                    // for each subsequent item, request ESI order stats using region in settings (The Forge is default).
                    $response = $this->retrieve([
                        'region_id' => $region_id,
                    ]);

                    $prices = $response->getBody();

                    // search the more recent entry in returned history.
                    $price = collect($prices)->where('order_count', '>', 0)
                        ->sortByDesc('date')
                        ->first();

                    if (is_null($price)) {
                        $price = (object) [
                            'average' => 0.0,
                            'highest' => 0.0,
                            'lowest' => 0.0,
                            'order_count' => 0,
                            'volume' => 0,
                        ];
                    }

                    Price::updateOrCreate([
                        'type_id' => $type_id,
                    ], [
                        'average' => $price->average,
                        'highest' => $price->highest,
                        'lowest' => $price->lowest,
                        'order_count' => $price->order_count,
                        'volume' => $price->volume,
                    ]);

                    return false;
                } catch (TemporaryEsiOutageException $e) {
                    logger()->error(sprintf('[Jobs][%s] History -> ESI is temporarily unavailable - Retry in 120 seconds.', $this->job->getJobId()));

                    if ($e->getPrevious() instanceof RequestFailedException) {
                        logger()->debug(sprintf('[Jobs][%s] History -> ESI remaining error count: %d', $this->job->getJobId(), $e->getPrevious()->getEsiResponse()->error_limit));
                    }

                    return true;
                } catch (RequestFailedException $e) {
                    logger()->error($e->getMessage());

                    // 404: this can happen when items get removed from the market
                    if($e->getEsiResponse()->getErrorCode() == 404) {
                        logger()->debug(sprintf('[Jobs][%s] History -> Banning %d from being processed for 1 month due to a 404 error.', $this->job->getJobId(), $type_id));
                        Cache::set(sprintf('%s.%d',self::TYPE_ID_BAN_CACHE_KEY_PREFIX, $type_id),true,self::TYPE_ID_BAN_DURATION);
                    }

                    return true;
                }
            }, function () {

                // specify callback to catch LimiterTimeoutException thrown by the throttler when limit is reached
                // we will only log the state for diagnose since it is expected.
                logger()->debug(sprintf('[Jobs][%s] History throttled -> Remaining types: %d. Delaying by %d.', $this->job->getJobId(), count($this->type_ids), self::ENDPOINT_RATE_LIMIT_WINDOW));

                return true;
            });

            if($delay) {
                $this->release(self::ENDPOINT_RATE_LIMIT_WINDOW);

                return;
            }
        }
    }
}
