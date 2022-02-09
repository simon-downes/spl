<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\contracts\queue;

enum TaskStatus: string {

    /**
     * Ready to be picked up by a worker
     */
    case QUEUED     = 'QUEUED';

    /**
     * Has been allocated to a worker
     */
    case PROCESSING = 'PROCESSING';

    /**
     * A worker has completed processing the job
     */
    case COMPLETE   = 'COMPLETE';

    /**
     * A worker encountered an error while processing the job
     */
    case ERROR      = 'ERROR';

    /**
     * Has been requested to be killed
     */
    case KILL       = 'KILL';

    /**
     * Has been killed
     */
    case KILLED     = 'KILLED';

}
