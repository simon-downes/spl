<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\contracts\queue;

interface Worker {

    /**
     * The main execution loop of the worker.
     *
     * @return void
     */
    public function run(): void;

    /**
     * Tell the worker to initiate a shutdown.
     *
     * @param string $reason   the reason for the shutdown
     * @return void
     */
    public function shutdown( $reason = '' ): void;

}
