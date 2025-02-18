<?php
/*
 * This file is part of the DebugBar package.
 *
 * (c) 2013 Maxime Bouroumeau-Fuseau
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace DebugBar\Storage;

/**
 * Stores collected data into files
 */
class TempFileStorage extends FileStorage
{
    /**
     * {@inheritdoc}
     */
    public function get($id)
    {
        $data = parent::get($id);
        try {
            unlink($this->makeFilename($id));
        } catch(\Throwable $e){}

        return $data;
    }
}
