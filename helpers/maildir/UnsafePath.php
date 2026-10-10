<?php

namespace EmailSquire\Helpers;

/** A task the helper refuses for good: an unsafe address or path. Never retried. */
final class UnsafePath extends \RuntimeException {}
