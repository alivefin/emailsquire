<?php

arch()->preset()->php();
arch()->preset()->security();
arch('no process execution in app code')->expect('App')->not->toUse('Symfony\Component\Process');
arch('env only in config')->expect('App')->not->toUse('env');
