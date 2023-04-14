<?php

$url = "https://coub.com/view/21f58m";
$tmpFolder = "tmp";
$coubFolder = 'coub';
$maxTime = 20;

if (preg_match("~coub\.com/(?:view|embed)/([\w\d]+)$~i", $url, $matches)) {

    $coubId = $matches[1];
    $coubInfoJson = file_get_contents("https://coub.com/api/v2/coubs/" . $coubId);
    $coubInfoArr = json_decode($coubInfoJson, true);

    if (empty($coubInfoJson) || isset($coubInfoArr['error'])) exit('Ошибка получения JSON');

    //Если нету максимального качества, то берем среднее
    $video = (isset($coubInfoArr['file_versions']['html5']['video']['high']['url']) ? $coubInfoArr['file_versions']['html5']['video']['high']['url'] : $coubInfoArr['file_versions']['html5']['video']['med']['url']);
    $audio = (isset($coubInfoArr['file_versions']['html5']['audio']['high']['url']) ? $coubInfoArr['file_versions']['html5']['audio']['high']['url'] : (isset($coubInfoArr['file_versions']['html5']['audio']['med']['url']) ? $coubInfoArr['file_versions']['html5']['audio']['med']['url'] : ''));

    //Создание временной папки (если ее нет)
    if (!file_exists($tmpFolder)) {
        mkdir($tmpFolder, 0777, true);
    }

    //Создание папки для готовых кубов (если ее нет)
    if (!file_exists($coubFolder)) {
        mkdir($coubFolder, 0777, true);
    }

    //Скачиваем видео
    $tmpVideo = $tmpFolder . "/{$coubId}_tmp.mp4";
    copy($video, $tmpVideo);

    //Добавляем для последующей обработки и склейки видео
    $fp = fopen($tmpVideo, 'r');
    fseek($fp, 2);
    $data = fread($fp, filesize($tmpVideo));
    $data = "\x00\x00" . $data;
    file_put_contents($tmpVideo, $data);

    //Продолжительность видео
    $videoDuration = $coubInfoArr['duration'];

    //Продолжительность аудио (если не указано, то устанавливаем 10 секунд)
    if (!empty($audio)) {
        $audioDuration = shell_exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 " . $audio);
    }
    else $audioDuration = 10;


    //Просчитываем кол-во циклов
    $loop = round(floatval($audioDuration) / floatval($videoDuration));
    $loopTmp = $videoDuration;
    $loopFinal = 0;


    //Обрезаем на минуте
    for ($i = 0; $i < $loop; $i++, $loopTmp += $videoDuration) {
        if ($loopTmp >= ($audioDuration < $maxTime ? $audioDuration : $maxTime)) {
            break;
        }
        $loopFinal++;
    }

    if ($loopTmp > 20) $loopTmp = $maxTime;

    //Если большой аудио файл
    if (!empty($loopFinal)) {

        //Создание очереди из видео для зацикливания
        for ($i = 0; $i < $loopFinal; $i++) {
            shell_exec("printf \"file '%s'\n\" {$coubId}_tmp.mp4 >> {$tmpFolder}/list_{$coubId}.txt 2>&1");
        }

        if (shell_exec("ffmpeg -f concat -i {$tmpFolder}/list_{$coubId}.txt -c copy {$tmpFolder}/loop-{$coubId}.mp4 -y 2>&1")) {

            unlink($tmpFolder . "/list_{$coubId}.txt");
            unlink($tmpFolder . "/{$coubId}_tmp.mp4");

            //Если большой аудио, режим его
            if ($audioDuration > $maxTime) {

                $video__duration = shell_exec("ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 {$tmpFolder}/loop-{$coubId}.mp4");
                $video__duration = trim($video__duration);

                shell_exec("ffmpeg -i {$audio} -ss 0 -t {$video__duration} -acodec copy -vcodec copy {$tmpFolder}/{$coubId}_audio.mp3 -y 2>&1");

                $audio = $tmpFolder . "/{$coubId}_audio.mp3";
            }

            if (shell_exec("ffmpeg -i {$tmpFolder}/loop-{$coubId}.mp4 -i {$audio}  -c:v copy -vcodec copy {$coubFolder}/coub-{$coubId}.mp4 -y 2>&1")) {

                unlink($tmpFolder . "/loop-{$coubId}.mp4");
                unlink($tmpFolder . "/{$coubId}_audio.mp3");
            }

        }

    }
    else {

        if (shell_exec("ffmpeg -i {$tmpFolder}/tmp/{$coubId}_tmp.mp4 -i {$audio}  -c:v copy -vcodec copy {$coubFolder}/coub-{$coubId}.mp4 -y 2>&1")) {

            unlink($tmpFolder . "/{$coubId}_tmp.mp4");
        }
    }


    //Выводим адрес готового видео
    echo $coubFolder . "/coub-{$coubId}.mp4";
}