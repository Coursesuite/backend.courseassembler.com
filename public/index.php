<?php
// error_reporting(E_ERROR);
// ini_set("display_errors", 1);
set_time_limit(300);

// currently powerpoint files are scaled to a width of 1280px
// a pixel width of 960 requires a scaling factor of 0.75. This is what pptx > pdf uses. pdf > html uses a scaling_factor of 1 which seems to result in the width of 1280.
// a pixel width of 1920 requires a scaling factory of 1.5
define('SCALING_FACTOR', 0.75); //  1.5); // found using trial and error
define("MAX_WIDTH", 1440);

$e = getenv("ORIGIN_URL");

// for local debugging, set the randomised server port here
// $e = "http://127.0.0.1:" . 55249;

header ("Access-Control-Allow-Origin: ". $e);
header ("Access-Control-Allow-Headers: *");
header ("Access-Control-Allow-Methods: POST, OPTIONS");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS' || $_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'PUT') {
    die();
}

require_once('../vendor/autoload.php');

$bugsnag = Bugsnag\Client::make('8a050b42bcd798011ceef380f768143d');
Bugsnag\Handler::register($bugsnag);

// $bugsnag->notifyException(new RuntimeException("Test error"));

// $headers = apache_request_headers();
// header('content-type: application/json');

use \CloudConvert\CloudConvert;
use \CloudConvert\Models\Job;
use \CloudConvert\Models\Task;
use IvoPetkov\HTML5DOMElement;

$bugsnag->leaveBreadcrumb('Calling out to licence verifier');

$verifier = Licence::validate(Request::post("hash"));
if (!$verifier->valid) Utils::stop(403, '{"error":"forbidden"}');

$fileid     = Request::post("fileid");              // file-kf783op-0
$name       = Request::post("name");                  // mybook.epub
$mime       = Request::post("type");                  // application/epub+zip
$url        = Request::post("url");
$website    = (Request::post("website") === "true");
$src        = Request::post("src");
$upload     = Request::file("blob");
$upload_filename = $upload ? $upload["tmp_name"] : "";
$upload_size = $upload ? $upload["size"] : 0;
$upload_name = $upload ? $upload["name"] : "";

// https://packagist.org/packages/ralouphie/mimey
$builder = \Mimey\MimeMappingBuilder::create();
$builder->add('application/vnd.apple.keynote', 'key');
$builder->add('application/x-iwork-keynote-sffkey', 'key');
$builder->add('application/vnd.apple.pages', 'pages');
$builder->add('application/x-iwork-pages-sffpages', 'pages');
$builder->add('application/vnd.apple.numbers', 'numbers');
$builder->add('application/x-iwork-numbers-sffnumbers', 'numbers');

$bugsnag->leaveBreadcrumb('Callout to file extension handler');
$Mimey = new \Mimey\MimeTypes;
$mimeext = $Mimey->getExtension($mime);


// AN EXTENSION IS REQUIRED
if (empty($mimeext)) {
    $mimeext = pathinfo($name, PATHINFO_EXTENSION);
}

if (empty($mimeext)) {
    Utils::Stop(500, '{"error":"The file was not understood or was not valid"}');
}

// CREATE A WORKING DIR
$jobsroot = realpath('../jobs');
$workingdir =  "{$jobsroot}/{$verifier->hash}/{$fileid}/";
if (!file_exists($workingdir)) mkdir ($workingdir, 0777, true);
if (!file_exists($workingdir)) {
    $bugsnag->leaveBreadcrumb(
        'Permissions are broken on Jobs folder!',
        \Bugsnag\Breadcrumbs\Breadcrumb::ERROR_TYPE
    );
    Utils::Stop(404, '{"error":"Permissions are preventing conversion from taking place"}');
}

WriteToLog("Job started " . time());
WriteToLog($_POST);
WriteToLog($upload);

// DETERMINE CONVERSION TYPE
$conversionTarget = $website ? "website" : "html";
$targetFormats = [
    "pdf" => ["odd","epub","mobi","lit","pages","numbers","ods","cdr","eps", "odt","pptx","ppt","key","numbers","pages","doc","docx","xls","xlsx"],
    "jpg" => ["psd","tiff","webp","ps","wps","azw","bmp","nef","raw","xps"],
    "png" => ["svg","ai"],
    "mp3" => ["m4a","wav","ogg"],
    "mp4" => ["m4v","mov","avi","mkv","wmv","flv","webm"],
];

if (!empty($mimeext)) {
    foreach($targetFormats as $key => $value) {
        if (in_array($mimeext, $value)) {
            $conversionTarget = $key;
        }
    }
}

WriteToLog("extension from mime=". $mimeext);
WriteToLog("conversionTarget=". $conversionTarget);

$bugsnag->leaveBreadcrumb('Conversion from ' . $mimeext . ' to ' . $conversionTarget);


// SET UP API
$CC_API = new CloudConvert([
    'api_key' => file_get_contents("../api.key"),
    'sandbox' => false
]);


// SET UP CONVERSION JOB
$job = new Job();
$job_result = "{$fileid}-html";

$bugsnag->leaveBreadcrumb('Job builder started');

switch ($conversionTarget) {
    case "html":
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import", $url)
            )
            ->addTask(
                ConvertToHtml("{$fileid}-import", $job_result ,$mimeext)
            );
    break;

    case "pdf":
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import", $url)
            )
            ->addTask(
                ConvertToPdf("{$fileid}-import", "{$fileid}-pdf", $mimeext)
            )
            ->addTask(
                ConvertPdfToHtml("{$fileid}-pdf", $job_result)
            );
    break;

    case "jpg":
        $job_result = "{$fileid}-jpg";
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import", $url)
            )
            ->addTask(
                ConvertToJpg("{$fileid}-import", $job_result, $mimeext)
            );
    break;

    case "png":
        $job_result = "{$fileid}-png";
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import", $url)
            )
            ->addTask(
                ConvertToPng("{$fileid}-import", $job_result, $mimeext)
            );
    break;

    case "website":
        $job_result = "{$fileid}-jpg";
        $job
            ->addTask(
                CaptureWebsite($url, $job_result)
            );
    break;

    case "mp3":
        $job_result = "{$fileid}-mp3";
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import", $url)
            )
            ->addTask(
                ConvertToMp3("{$fileid}-import", $job_result, $mimeext)
            );
    break;

    case "mp4":
        $job_result = "{$fileid}-mp4";
        $job
            ->addTask(
                CreateImportTask("{$fileid}-import", $url)
            )
            ->addTask(
                ConvertToMp4("{$fileid}-import", $job_result, $mimeext)
            );
    break;

}


// EXPORT THE CONVERSION RESULT
$job->addTask(CreateExportTask($job_result, $fileid));


// UPLOAD THE JOB
$CC_API->jobs()->create($job);
if ($conversionTarget === "website" || ($upload === false && !empty($url))) {
    WriteToLog("Nothing to upload, url=" . $url);
    // nothing to do
} else {
    $uploadTask = $job->getTasks()->whereName("{$fileid}-import")[0];
    WriteToLog("Uploading {$upload_filename} ({$upload_size} bytes)");
    $CC_API->tasks()->upload($uploadTask, fopen($upload_filename, 'r'), $name);

    // debug
    if (in_array($conversionTarget, ["mp3","mp4"])) {
        move_uploaded_file($upload_filename, $workingdir . '/' . $upload_name);
    }

}


// WAIT FOR CONVERSION
$bugsnag->leaveBreadcrumb('Waiting on conversion');
WriteToLog('Waiting on conversion');
$CC_API->jobs()->wait($job);


// DOWNLOAD THE RESULTS
$converted_file_contents = "";
$converted_file_name = "";
foreach ($job->getExportUrls() as $file) {
    WriteToLog($file);
    $bugsnag->leaveBreadcrumb('Downloading result');
    $source = $CC_API->getHttpTransport()->download($file->url)->detach();
    $converted_file_name = $file->filename;
    $dest = fopen($workingdir . '/' . $converted_file_name, 'w');
    stream_copy_to_stream($source, $dest);
    $converted_file_contents = file_get_contents($workingdir . '/' . $converted_file_name);
}

if (empty($converted_file_contents)) {
    Utils::Stop(500, '{"error":"An error occurred converting this file (unsupported)."}');
}

// CREATE THE FILEINFO PAYLOAD
$result = new stdClass();
$result->payload = new stdClass();
if ($website || in_array($conversionTarget, ["jpg","png"])) {
    $result->name = $name;
    $result->format = $conversionTarget;
    $result->kind = 'image';
    $result->source = $url;
    $result->payload->image = "data:image/" . ($conversionTarget==="jpg" ? "jpeg" : "png") . ";base64," . base64_encode($converted_file_contents);
    $result->payload->name = $name;
    $result->payload->backgroundColor = '#ffffff';

} elseif (in_array($conversionTarget, ["mp3","mp4"])) {
    // write the file data directly to the response
    $outputname = $converted_file_name; // 'output.' . $conversionTarget;
    $outputpath = $workingdir . '/' . $outputname;
    header('Content-Disposition: attachment; filename=' . $outputname . ';');
    header('Content-Type: ' . ($conversionTarget === 'mp3' ? 'audio/mp3' : 'video/mp4'));
    header('Content-Length: ' . filesize($outputpath));
    ob_end_flush(); // ensure that any buffered output has been cleared
    $fp = fopen($outputpath, 'rb');
    fpassthru($fp);
    fclose($fp);
    exit;

} else {
    $result->name = pathinfo($name, PATHINFO_FILENAME);
    $result->payload = PostProcessing($upload_filename, $mimeext, $converted_file_contents);
    $result->format = $conversionTarget;
    $result->kind = 'file';
    $result->type = $mime;
    $result->src = $src;
}

// WRITE OUTPUT AND STOP
$json = json_encode($result, JSON_NUMERIC_CHECK); //  | JSON_PARTIAL_OUTPUT_ON_ERROR);
WriteToLog($json);
Utils::stop(200, $json, false, 'application/json', $workingdir);

// UTILITIES
function WriteToLog($contents) {
    global $workingdir;
    if (gettype($contents) !== "string") $contents = var_export($contents, true);
    file_put_contents($workingdir . '/log.txt', $contents . PHP_EOL, FILE_APPEND);
}


// CLOUD CONVERT TASKS
function CreateImportTask($output, $url = '') {
    if (empty($url)) {
        $TASK = (new Task('import/upload', $output));
        WriteToLog("Task is Upload");
    } else {
        $TASK = (new Task('import/url', $output))
            ->set('url', $url)
            ->set('filename','output.pdf');
        WriteToLog("Task is URL");
    }
    return $TASK;
}

function ConvertToZip($input, $output) {
    $TASK = (new Task('archive', $output))
        ->set('output_format', 'zip')
        ->set('engine', 'archivetool')
        ->set('input', [$input])
        ->set('filename', 'output.zip');
    return $TASK;
}

function ConvertPdfToHtml($input, $output) {
    $TASK = (new Task('convert', $output))
        ->set('input_format', 'pdf')
        ->set('output_format', 'html')
        ->set('engine', 'pdf2htmlex')
        ->set('input', [$input])
        ->set('outline', false)
        ->set('zoom', 1)
        ->set('embed_css', true)
        ->set('embed_javascript', true)
        ->set('embed_images', true)
        ->set('embed_fonts', true)
        ->set('split_pages', false)
        ->set('bg_format', 'jpg');
    return $TASK;
}

function CaptureWebsite($url, $output) {
    $TASK = (new Task('capture-website', $output))
        ->set('url', $url)
        ->set('output_format', 'jpg')
        ->set('engine', 'chrome')
        ->set('screen_width', MAX_WIDTH)
        ->set('fit', 'max')
        ->set('quality', 90)
        ->set('timeout', 300)
        ->set('wait_until', 'networkidle2')
        ->set('wait_time', 0)
        ->set('filename', 'output.jpg');
    return $TASK;
}

function ConvertToPdf($input, $output, $format) {
    $TASK = (new Task('convert', $output))
        ->set('input_format', $format)
        ->set('output_format', 'pdf')
        ->set('input', [$input])
        ->set('pdf_a', false)
        ->set('filename', 'output.pdf');
    return $TASK;
}

function ConvertToHtml($input, $output, $format) {
    $TASK = (new Task('convert', $output))
        ->set('input_format', $format)
        ->set('output_format', 'html')
        ->set('input', [$input])
        ->set('embed_images', true)
        ->set('filename', 'output.html');
    return $TASK;
}

function ConvertToJpg($input, $output, $format) {
    $TASK = (new Task('convert', $output))
        ->set('input_format', $format)
        ->set('output_format', 'jpg')
        ->set('input', [$input])
        ->set('fit', 'max')
        ->set('strip', true)
        ->set('quality', 90)
        ->set('width', MAX_WIDTH)
        ->set('filename', 'output.jpg');
    return $TASK;
}

function ConvertToPng($input, $output, $format) {
    $TASK = (new Task('convert', $output))
        ->set('input_format', $format)
        ->set('output_format', 'png')
        ->set('input', [$input])
        ->set('pixel_density', 96)
        ->set('filename', 'output.png');
    return $TASK;
}

function ConvertToMp3($input, $output, $format) {
    $TASK = (new Task('convert', $output))
        ->set('input', [$input])
        ->set('output_format', 'mp3');
        // ->set('input_format', $format)
        // ->set('engine', 'ffmpeg')
        // ->set('audio_codec', 'mp3')
        // ->set('audio_qscale', 0)
        // ->set('filename', 'output.mp3');
    return $TASK;
}

function ConvertToMp4($input, $output, $format) {
    $TASK = (new Task('convert', $output))
        // ->set('input_format', $format)
        ->set('input', [$input])
        ->set('output_format', 'mp4');
        // ->set('engine', 'ffmpeg')
        // ->set('video_codec', 'x264')
        // ->set('crf', 23)
        // ->set('preset', 'fast')
        // ->set('subtitles_mode', 'none')
        // ->set('audio_codec', 'aac')
        // ->set('audio_bitrate', 128)
        // ->set('filename', 'output.mp4');
    return $TASK;
}

function CreateExportTask($input, $fileid) {
    $TASK = (new Task('export/url', $fileid . '-export'))
        ->set('input', $input);
    return $TASK;
}

/**
 * PostProcessing - Performs any modifications to the final output html before being returned to the callee
 * @param $input string input file
 * @param #extension string file extension of the input file
 * @param $output string The html of the conversion output 
 * @return stdClass - containing the HTML object and any public file references to be returned to the callee
 */
function PostProcessing($input, $extension, $html) {
    $payload = new stdClass();
    $payload->html = $html;
    switch ($extension) {
        case 'pptx':
            $payload = ConvertPowerpointMedia($input, $html);
            break;
    }
    return $payload;
}

/**
 * Takes the original powerpoint file and calculates the position of embedded media and modifies the html to reference the files
 * @param $filename string The original powerpoint file (uploaded by the user)
 * @param $html string The html of the conversion output (what cloudconvert gave us)
 * @return stdClass - an object containing these keys
 *                    - html - the modified html string. videos are embedded in base64 encoded data-urls
 *                   - files - an array of file urls that can be downloaded by the callee (e.g. page audio)
 */
function ConvertPowerpointMedia($file_reference, $html) {
    global $bugsnag;

    $bugsnag->leaveBreadcrumb('ConvertPowerpointMedia');
    WriteToLog("ConvertPowerpointMedia called");

    $result = new stdClass();
    $result->html = $html;
    $modifed = false;

    $ppt = new PPTLoader(SCALING_FACTOR);
    $ppt->load($file_reference);
    $pptSlides = $ppt->getSlides();
    // file_put_contents($workingdir . '/slides.txt', var_export($pptSlides, true));

    // data returned looks like this. slides are 1-based
    // {
    //     "slide1.xml": {
    //         "baseFile": "slide1.xml", // this slide has no media key so it can be ignored
    //     },
    //     "slide2.xml": {
    //         "name": string,
    //         "title": string,
    //         "extn": mp4 | empty,
    //         "x": float,          // values need to be scaled by 1.5 to match the coolwanglu output size
    //         "y": float,
    //         "width": float,
    //         "height": float,
    //         "type": mime/type | youtube | vimeo | external
    //         "media": data:mime/type;base64,... | youtube-embed | vimeo-embed | external-url
    //     }
    // }

    // we need to load the html and then go through each slide and add the media as per the above
    $dom = new IvoPetkov\HTML5DOMDocument();
    $dom->loadHTML($html);
    $container = $dom->querySelector("#page-container");
    $slides = $container->querySelectorAll("div[data-page-no]");
    foreach ($slides as $slide) {
        $slide_no = $slide->getAttribute("data-page-no");
        $slide_no = intval($slide_no);
        if ($slide_no === 0) continue; // no such index
        $xml = $pptSlides["slide" . $slide_no . ".xml"];
        if (property_exists($xml, "media")) {
            WriteToLog("Modifying slide " . $slide_no . "; type=" . $xml->type);
            $modifed = true;
            $pc = $slide->querySelector(".pc");
            $style = "" .
                "position: absolute; " .
                "top: " . $xml->y . "px; " .
                "left: " . $xml->x . "px; " .
                "width: " . $xml->width . "px; " .
                "height: " . $xml->height . "px; " .
                "";
            if ($xml->type === "youtube" || $xml->type === "vimeo") {
                $element = $dom->createElement("iframe");
                $element->setAttribute("src", $xml->media);
                $element->setAttribute("style",$style);
                $element->setAttribute("frameborder", 0);
                $element->setAttribute("title", $xml->title);
                $element->setAttribute("allow", "autoplay *; encrypted-media; fullscreen *");
                $pc->appendChild($element);
            } else if ($xml->type === "external" || strpos($xml->type, "video/") !== false) {
                $element = $dom->createElement("video");
                $element->setAttribute("src", $xml->media);
                $element->setAttribute("controls", "controls");
                $element->setAttribute("style", $style);
                $pc->appendChild($element);
            } else if (strpos($xml->type, "audio/") !== false) {
                // the audio file is per slide, but we are returning unsplit document.
                // so we might return multple audios that need to be attached to the new storage elemnts for each page
                $result->audio["s".$slide_no] = $xml->media;
            }
        }
    }

    // only modify the html if any slides had media
    if ($modifed) {
        $result->html = $dom->saveHTML();
        // file_put_contents($workingdir . '/modified.html', $result->html);
    }

    // becomes the payload object
    return $result;

}
