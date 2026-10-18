<?php
class JsonProductImportJobInput
{
    public static function chunks($json,$offset,$total)
    {
        if (!is_string($json) || strlen($json)>1048576 || $offset<0 || $offset%100!==0 || $total<1 || $total>100000) { throw new InvalidArgumentException('Invalid upload size or offset.'); }
        $rows=json_decode($json);
        if (json_last_error()!==JSON_ERROR_NONE || !is_array($rows) || !count($rows) || count($rows)>1000 || $offset+count($rows)>$total || ($offset+count($rows)<$total && count($rows)%100!==0)) { throw new InvalidArgumentException('Upload 1–1000 records in complete 100-record chunks, except the final chunk.'); }
        $result=array();
        foreach(array_chunk($rows,100) as $chunk){$result[]=JsonProductPreviewValidator::parse(json_encode($chunk,JSON_THROW_ON_ERROR));}
        return $result;
    }
}
