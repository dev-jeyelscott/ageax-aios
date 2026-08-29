<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown whenever the Context Gateway cannot resolve exactly one registered AIOS Project for a
 * repository directory or explicit reference. Covers unregistered, ambiguous, and otherwise unsafe
 * inputs; every case must fail closed instead of guessing a Project.
 */
class ProjectResolutionFailed extends Exception
{
    //
}
