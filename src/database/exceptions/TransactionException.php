<?php declare(strict_types=1);
/*
 * This file is part of the simon-downes/spl package which is distributed under the MIT License.
 * See LICENSE.md or go to https://github.com/simon-downes/spl for full license details.
 */
namespace spl\database\exceptions;

/**
 * Thrown if an error occurs whilst executing begin, commit or rollback of a transaction.
 */
class TransactionException extends DatabaseException {}
