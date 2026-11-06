<?php

namespace App\DocumentProcessing;

use RuntimeException;

/** The document was read but yielded nothing usable. Retrying will not help. */
final class ExtractionFailed extends RuntimeException {}
