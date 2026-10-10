<?php

namespace EmailSquire\Helpers;

/** A task the helper refuses for good: an unsafe address or path, an unknown type, a failed restore precondition. Never retried. */
final class UnsafePath extends \RuntimeException {}
