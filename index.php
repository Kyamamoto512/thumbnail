<?php
//元画像のURL
$img =  urldecode($_GET['url']);

$memcache = new Memcached();

//環境変数 CASHE_ADDにキャッシュサーバーのアドレスを設定しておく
$memcache->addServer($_ENV["CASHE_ADD"], 11211);

//GETパラメータをjson化し、さらにそれを64baseエンコードさせたものをcasheのkeyとする
$key = json_encode($_GET);
$key = base64_encode($key);

checkCashe($key,$memcache);

if(!empty($_GET['w'])&&!empty($_GET['h'])){
	//$元の画像のサイズ、タイプを取得する
	list($w, $h, $type) = getimagesize($img);

	//サムネイルのサイズ
	$thumbW = $_GET['w'];
	$thumbH = $_GET['h'];

	//元サイズより幅指定が大きい場合はそのまま出力
	if( $thumbW>$w || $thumbH>$h ){
		normal_image($img,$key,$memcache);
	}

	$orgRatio = $w/$h;
	$thumRatio = $thumbW/$thumbH;

	if($thumRatio<1){
	    $diffH = $h;
	    $diffW = $w*($thumRatio/$orgRatio);

	    $startH = 0;
	    $startW = ($w-$diffW)/2;
	}else{

	    $diffW = $w;
	    $diffH = $h/$thumRatio;

	    $startW = 0;
	    $startH = ($h-$diffH)/2;
	}

	//var_dump($diffW,$diffH,$startW,$startH);exit;

	//サムネイルになる土台の画像を作る
	$thumbnail = imagecreatetruecolor($thumbW, $thumbH);

	//加工前のファイルをフォーマット別に読み出す（この他にも対応可能なフォーマット有り）
	switch ($type) {
	    case IMAGETYPE_JPEG:
	        $baseImage = imagecreatefromjpeg($img);
	        $imgExtension = 'jpg';
	        break;
	    case IMAGETYPE_PNG:
	        $baseImage = imagecreatefrompng($img);
	        $imgExtension = 'png';
	        break;
	    case IMAGETYPE_GIF:
	        $baseImage = imagecreatefromgif($img);
	        $imgExtension = 'gif';
	        break;
	    default:
	        exit;
	}

	//サムネイルになる土台の画像に合わせて元の画像を縮小しコピーペーストする
	imagecopyresampled($thumbnail, $baseImage, 0, 0, $startW, $startH, $thumbW, $thumbH, $diffW, $diffH);

	header('Content-type: image/'.$imgExtension);
	header('Cache-Control: max-age=86400');

	//画質設定 指定がない場合は50%のクオリティ
	$quality = !empty($_GET["q"])?$_GET["q"]:50;

	//フォーマット別に出力
	ob_start();
	switch ($type) {
	    case IMAGETYPE_JPEG:
	        imagejpeg($thumbnail, null, $quality);
	        break;
	    case IMAGETYPE_PNG:
	        imagepng($thumbnail, null, $quality);
	        break;
	    case IMAGETYPE_GIF:
	        imagegif($thumbnail, null, $quality);
	        break;
	}
	$imageData = ob_get_contents();
	ob_end_clean();

	// メモリの開放
	imagedestroy($thumbnail);

	echo $imageData;

	setCashe($key,$imageData,$imgExtension,$memcache);
	exit;
}

normalImage($img,$key,$memcache);


//そのままイメージを出力させる場合の処理
function normalImage($img,$key,$memcache){
	$file_info = pathinfo($img);
	$img_extension = strtolower($file_info['extension']);
	if(empty($img_extension)){
		$img_extension = 'jpg';
	}else{
		$img_extension = strtolower($file_info['extension']);
	}
	header('Content-type: image/'.$img_extension);
	header('Cache-Control: max-age=86400');
	$imageData = file_get_contents($img);

	echo $imageData;

	setCashe($key,$imageData,$img_extension,$memcache);
	exit;
}

//casheに登録されているかをチェックしある場合は出力して終了
function checkCashe($key,$memcache){
	$Date = $memcache->get($key);

	if(!empty($Date)){
		$Date = json_decode($Date,true);
		header('Content-type: image/'.$Date["ex"]);
		header('Cache-Control: max-age=86400');
		echo base64_decode($Date["img"]);
		exit;
	}
}

//memcasheに値を入れる
function setCashe($key,$img,$ex,$memcache){
	$img64 = base64_encode($img);
	//半日だけ有効なcache
	$memcache->set($key,json_encode(array('img'=>$img64,'ex'=>$ex)), 60*60*12);
}
