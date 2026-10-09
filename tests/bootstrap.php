<?php

/**
 * @file tests/bootstrap.php
 *
 * Copyright (c) 2026 the contactForm plugin authors
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @brief Test bootstrap.
 *
 * The classes under test are deliberately free of OJS dependencies, so the
 * suite needs neither Composer nor a running OJS installation: it just loads
 * the three guard classes directly.
 */

declare(strict_types=1);

require_once __DIR__ . '/../classes/spam/HoneypotGuard.php';
require_once __DIR__ . '/../classes/spam/ContentHeuristics.php';
require_once __DIR__ . '/../classes/spam/Throttle.php';
