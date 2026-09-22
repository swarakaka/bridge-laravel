<x-bridge::head :protocol="7" build="custom" />
<x-bridge::app id="root" :page="['protocol' => 1, 'type' => 'page', 'component' => 'Custom', 'url' => '/c', 'props' => ['x' => '<b>'], 'build' => null]" />
<x-bridge::app id="second" :page="false" />
