<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\contracts\queue;

enum TaskStatus: string {

    /**
     * Ready to be picked up by a worker.
     */
    case QUEUED     = 'QUEUED';

    /**
     * Has been allocated to a worker.
     */
    case PROCESSING = 'PROCESSING';

    /**
     * Task was successfully completed.
     */
    case COMPLETE   = 'COMPLETE';

    /**
     * Task was not successfully completed.
     */
    case FAILED     = 'FAILED';

}
