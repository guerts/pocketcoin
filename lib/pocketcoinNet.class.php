<?php

class pocketcoinNet extends waNet
{
    public function setOption($option, $value)
    {
        if (array_key_exists($option, $this->options)) {
            $this->options[$option] = $value;
        }
    }

    public function queryJson($url, $content = [], $method = self::METHOD_GET)
    {
        $this->setOption('format', self::FORMAT_JSON);

        return $this->query($url, $content, $method);
    }

    public function queryRaw($url, $content = [], $method = self::METHOD_GET)
    {
        $this->setOption('format', self::FORMAT_RAW);

        return $this->query($url, $content, $method);
    }
}
