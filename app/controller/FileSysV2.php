<?php

namespace app\controller;

use app\BaseController;

use think\facade\Config;
use think\facade\Db;
use think\facade\Filesystem;
use think\facade\Log;

class FileSysV2 extends BaseController
{  

    public function GetFiles(string $Path = '', int $Recu = 1)
    {
        $FileList=self::GetFolderInfo($Path, $Recu);
        if($FileList!==null)
        {
            return json_encode(['code'=>0,'data'=>$FileList]);
        }
        else
        {
            return json_encode(['code'=>101,'data'=>'文件夹不存在']);
        }        
    }

    protected static function GetFolderInfo(string $Path, int $Recu)
    {
        $root = Config::get('filesystem.webfs.root');
        $FolderPath="$root/$Path";
        if (is_dir($FolderPath))
        {
            if ($dp = opendir($FolderPath)) 
            {     
                $FileList=array();
                $FolderList=array();
                while (($file=readdir($dp)) != false) 
                {
                    $FilePath=self::NormalizePath("$Path/$file");
                    $RealPath="$FolderPath/$file";
                    if (is_dir($RealPath)) 
                    {
                        if($file!='.' && $file!='..')
                        {
                            $FolderList[]=$FilePath;
                            $FileList[]=self::GetFileInfo($FilePath, $RealPath);
                        }                        
                    } 
                    else if(!str_ends_with(strtolower($file),'.meta'))
                    {
                        $FileList[]=self::GetFileInfo($FilePath, $RealPath);
                    }
                }
                closedir($dp);
                if($Recu)
                {
                    foreach($FolderList as $Value)
                    {
                        $FileList=array_merge($FileList, self::GetFolderInfo($Value, $Recu));
                    }
                }                
                return $FileList;
            } 
            else
            {
                Log::record("打开目录 $Path 失败",'notice');
            }
        }
        else
        {
            Log::record("$Path 不是目录",'notice');
        }
        return null;
    }

    public function GetFile(string $Path)
    {
        $root = Config::get('filesystem.webfs.root');
        $Path = self::NormalizePath($Path);
        $RealPath = "$root//$Path";
        $FileInfo=self::GetFileInfo($Path, $RealPath);
        if($FileInfo)
        {
            return json_encode(['code'=>0,'data'=>$FileInfo]);
        }
        else
        {
            return json_encode(['code'=>701,'data'=>'文件不存在']);
        }
    }

    protected static function GetFileInfo(string $Path, string $RealPath)
    {
        if(file_exists($RealPath))
        {
            $CreateTime=filectime($RealPath);
            $LastWriteTime=filemtime($RealPath);
            $Size=filesize($RealPath);
            if(is_dir($RealPath))
            {
                return ['Path'=>$Path,'IsDir'=>true, 'CreateTime'=>$CreateTime, 'LastWriteTime'=>$LastWriteTime];
            }
            else
            {
                $FileMeta=self::GetFileMeta($RealPath);
                return ['Path'=>$Path,'IsDir'=>false,'GUID'=>$FileMeta['GUID'],'Alias'=>$FileMeta['Alias'], 'Size'=>$Size, 'CreateTime'=>$CreateTime, 'LastWriteTime'=>$LastWriteTime];
            }
        }        
        
    }

    protected static function GetFileMeta(string $Path, string $Alias='')
    {
        if (is_dir($Path))
            return null;
        $MetaPath=$Path.'.meta';
        if(file_exists($MetaPath))
        {
            $FileContent=file_get_contents($MetaPath);
            if($FileContent)
            {
                return json_decode($FileContent, true);
            }
        }
        else
        {
            return self::CreateFileMeta($Path, $Alias);
        }
    }

    protected static function SaveFileMeta(string $Path, $FileMeta) : bool
    {
        if(file_put_contents($Path.'.meta',json_encode($FileMeta)))
            return true;
        else
            return false;
    }

    protected static function CreateFileMeta(string $Path, string $Alias='')
    {
        $MetaPath=$Path.'.meta';
        $FileMeta=['GUID'=>self::create_guid(), 'Alias'=>$Alias];
        file_put_contents($MetaPath,json_encode($FileMeta));
        return $FileMeta;
    }

    protected static function NormalizePath($path, $separator = '/') 
    {
        if ($separator == '/')        
            $path = str_replace('\\', '/', $path);
        else
            $path = str_replace('/', '\\', $path);
        return array_reduce(
            preg_split('/[\/\\\\]/', $path, -1, PREG_SPLIT_NO_EMPTY),
            function($absolutes, $part) use($separator) {
                if ($part === '.') return $absolutes;
                if ($part === '..') 
                {
                    if (is_null($absolutes))
                        return $absolutes;
                    else
                        return dirname($absolutes);
                }
                if (is_null($absolutes)) return $part;
                if ($absolutes === $separator) return $absolutes.$part;
                return $absolutes.$separator.$part;
            }
        );
    }

    protected static function GetRelativePath(string $Path, string $Root)
    {
        $Path = self::NormalizePath($Path);
        $Root = self::NormalizePath($Root);
        if(str_starts_with(strtolower($Path), strtolower($Root)))
            return substr($Path, strlen($Root) + 1);
        else
            return null;
    }

    public function CreateFolder(string $Path)
    {
        $root = Config::get('filesystem.webfs.root');
        $Path = self::NormalizePath($Path);
        $RealPath = "$root/$Path";
        if(self::CreateDir($RealPath))
        {
            return json_encode(['code'=>0,'data'=>self::GetFileInfo($Path, $RealPath)]);
        }
        else
        {
            $result['code']=102;
            $result['data']='创建目录失败';
            return json_encode($result);
        }
    }

    protected static function CreateDir(string $Path) : bool
    {
        $Path = self::NormalizePath($Path);
        $DirList = preg_split('/[\/]/', $Path);
        if($DirList)
        {
            $CheckPath = null;
            foreach($DirList as $Value)
            {
                if($CheckPath)
                    $CheckPath = "$CheckPath/$Value";
                else
                    $CheckPath = $Value;
                if(file_exists($CheckPath))
                {
                    if(!is_dir($CheckPath))
                        return false;
                }
                else
                {
                    if(!mkdir($CheckPath))
                        return false;
                }
            }
            return true;
        }
        return false;
    }

    public function UploadFile(string $FolderPath = '', string $Alias='')
    {
        $root = Config::get('filesystem.webfs.root');
        $FolderPath=self::NormalizePath($FolderPath);
        $RealPath = "$root/$FolderPath";
        if((!file_exists($RealPath)) || is_dir($RealPath))
        {
            if(!file_exists($RealPath))
            {
                if(!self::CreateDir($RealPath))
                {
                    return json_encode(['code'=>202,'data'=>'创建目录失败']);
                }
            }
            $FileInfos=array();
            foreach($_FILES as $File)
            {
                if($File['error'] == UPLOAD_ERR_OK)
                {
                    $FileName = $File['name'];
                    if(empty($FolderPath))                
                        $FilePath = $FileName;
                    else                
                        $FilePath = "$FolderPath/".$FileName;                
                    $WritePath = "$root/$FilePath";
                    if(move_uploaded_file($File['tmp_name'],$WritePath))
                    {          
                        $FileInfos[] = self::GetFileInfo($FilePath, $WritePath);
                    }
                    else
                    {
                        return json_encode(['code'=>204,'data'=>'写入文件失败']);
                    }       
                }
                else
                {
                    return json_encode(['code'=>203,'data'=>'文件上传失败err='.$File['error']]);
                }
                    
            }       
            return json_encode(['code'=>0,'data'=>$FileInfos]);
        }
        else
        {
            return json_encode(['code'=>201,'data'=>'目录名已被使用，并且不是目录']);
        }        
                 
    }

    public function RemoveFile(string $Path)
    {     
        $root = Config::get('filesystem.webfs.root');
        $Path=self::NormalizePath($Path);
        $RealPath="$root/$Path";
        if(file_exists($RealPath))
        {
            if(is_dir($RealPath))
            {
                //删除目录
                if(self::DeleteDir($RealPath))
                {
                    $result['code']=0;
                    $result['data']=['Path'=>$Path];
                    return json_encode($result);
                }
                else
                {
                    $result['code']=301;
                    $result['data']='目录删除失败';
                    return json_encode($result);
                }
            }
            else
            {
                //删除文件
                if(self::DeleteFile($RealPath))
                {
                    $result['code']=0;
                    $result['data']=['ID'=>$Path];
                    return json_encode($result);
                }
                else
                {
                    $result['code']=302;
                    $result['data']='文件删除失败';
                    return json_encode($result);
                }
            }
        }
        else
        {
            $result['code']=303;
            $result['data']='文件不存在';
            return json_encode($result);
        }
    }
    
    protected static function DeleteFile(string $Path)
    {
        if(unlink($Path))
        {
            if(!unlink("$Path.meta"))
                Log::record("删除文件 $Path 的meta文件失败",'notice');
            return true;
        }
        else
        {
            Log::record("删除文件 $Path 失败",'notice');
        }
        return false;
    }
    
    protected static function DeleteDir(string $dir) : bool
    {        
        if (is_dir($dir)) 
        {
            if(rmdir($dir))
            {
                return true;
            }
            else
            {
                if ($dp = opendir($dir)) 
                {     
                    while (($file=readdir($dp)) != false) 
                    {
                        $Path="$dir/$file";                        
                        if (is_dir($Path)) 
                        {
                            if($file!='.' && $file!='..')
                            {
                                if(!self::DeleteDir($Path))
                                return false;
                            }                        
                        } 
                        else 
                        {                            
                            if(!unlink($Path))
                            {
                                Log::record("删除文件 $Path 失败",'notice');
                                return false;
                            }                            
                        }
                    }
                    closedir($dp);
                    if(rmdir($dir))
                    {
                        return true;
                    }
                    else
                    {
                        Log::record("删除目录 $dir 失败",'notice');
                    }
                } 
                else 
                {
                    Log::record('删除目录时无权限','notice');
                }
            }            
        }
        else
        {
            Log::record('要删除的不是目录','notice');
        }
        return false;
    }
    
    public function DownloadFile(string $Path)
    {
        $root = Config::get('filesystem.webfs.root');
        $Path=self::NormalizePath($Path);
        $RealPath="$root/$Path";
        if(file_exists($RealPath))
        {
            if(!is_dir($RealPath))
            {
                self::NginxDownloadFile("/webfs/$Path");
            }
            else
            {
                return json("Target is Folder")->code(404);
            }
        }
        else
        {
            return json("File not exist")->code(404);
        }        
    }

    protected static function NginxDownloadFile(string $realPath, string $fileName=null, int $rate=null)
    {
 
        if (!$fileName){
            //获取文件名并处理文件名称
            $fileName = basename($realPath);
        }
        //处理ie中文文件名，避免文件名乱码
        $encoded_filename = rawurlencode($fileName);
    
        $ua = $_SERVER["HTTP_USER_AGENT"];
        header('Content-type: application/octet-stream');
        if (preg_match("/MSIE/", $ua)) {
            header('Content-Disposition: attachment; filename="' . $encoded_filename . '"');
        } else if (preg_match("/Firefox/", $ua)) {
            header("Content-Disposition: attachment; filename*=\"utf8''" . $fileName . '"');
        } else {
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
        }
    
        //让Xsendfile发送文件
        header('X-Accel-Redirect: ' . $realPath);
    
        //限制下载速度 按字节计算 100KB = 1024 * 100,为0不限速
        if($rate){
            header('X-Accel-Limit-Rate: ' . 1024 * $rate);
        }
        //是否开启缓冲 yes|no
        //header('X-Accel-Buffering: yes' );
        //字符集
        //header('X-Accel-Charset: utf-8' );
        //exit;
    }

    public function MoveFile(string $Path, string $TargetPath)
    {
        $root = Config::get('filesystem.webfs.root');  
        $Path=self::NormalizePath($Path);
        $RealPath="$root/$Path";
        $TargetPath=self::NormalizePath($TargetPath);
        $RealTargetPath="$root/$TargetPath";
        if(file_exists($RealPath))
        {
            if(!file_exists($RealTargetPath))
            {
                $TargetDir=dirname($RealTargetPath);
                if(!file_exists($TargetDir))
                {
                    if(!self::CreateDir($TargetDir))
                    {
                        return json_encode(['code'=>502,'data'=>'创建目录失败']);
                    }
                }
                if(rename($RealPath, $RealTargetPath))
                {
                    if(!is_dir($RealPath))
                    {
                        if(!rename("$RealPath.meta", "$RealTargetPath.meta"))
                        {
                            Log::record("移动 $RealPath 的meta文件失败",'notice');
                        }
                    }
                    return json_encode(['code'=>0,'data'=>['Path'=>$TargetPath, 'OldPath'=>$Path]]);
                }
                else
                {
                    return json_encode(['code'=>504,'data'=>'移动文件失败']);
                }
            }
            else
            {
                return json_encode(['code'=>503,'data'=>'目标文件名已经存在']);
            }            
        }
        else
        {
            return json_encode(['code'=>501,'data'=>'文件不存在']);
        }        
    }

    public function SetAlias(string $Path, string $Alias)
    {
        $root = Config::get('filesystem.webfs.root');  
        $Path=self::NormalizePath($Path);
        $RealPath="$root/$Path";
        if(file_exists($RealPath) && (!is_dir($RealPath)))
        {
            $FileMeta=null;
            if(file_exists("$RealPath.meta"))
            {
                $FileMeta=self::GetFileMeta($RealPath, $Alias);
                $FileMeta['Alias']=$Alias;
                if(!self::SaveFileMeta($RealPath, $FileMeta))
                    $FileMeta=null;
            }
            else
            {
                $FileMeta=self::CreateFileMeta($RealPath, $Alias);
            }
            if($FileMeta)
                return json_encode(['code'=>0,'data'=>['Path'=>$Path, 'Alias'=>$FileMeta['Alias']]]);
            else
                return json_encode(['code'=>602,'data'=>'设置别名失败']);
        }       
        else
        {
            return json_encode(['code'=>601,'data'=>'文件不存在']);
        }
    }

    public static function create_guid($namespace = '') { 
      static $guid = '';
      $uid = uniqid("", true);
      $data = $namespace;
      $data .= $_SERVER['REQUEST_TIME'];
      $data .= $_SERVER['HTTP_USER_AGENT'];
      $data .= $_SERVER['LOCAL_ADDR'];
      $data .= $_SERVER['LOCAL_PORT'];
      $data .= $_SERVER['REMOTE_ADDR'];
      $data .= $_SERVER['REMOTE_PORT'];
      $hash = strtoupper(hash('ripemd128', $uid . $guid . md5($data)));
      $guid = '{' .
          substr($hash, 0, 8) .
          '-' .
          substr($hash, 8, 4) .
          '-' .
          substr($hash, 12, 4) .
          '-' .
          substr($hash, 16, 4) .
          '-' .
          substr($hash, 20, 12) .
          '}';
      return $guid;
     }
}