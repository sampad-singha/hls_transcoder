<!DOCTYPE html>
<html>
<head>
    <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet" />
    <title>HLS Transcoder</title>
</head>
<body >

<div style="width: 800px;">
    <video id="hls-player" class="video-js vjs-fluid vjs-big-play-centered" controls preload="auto">
        <source src="{{asset('storage/hls/test_folder/master.m3u8')}}" type="application/x-mpegURL">

        <track kind="subtitles" src="{{asset('storage/hls/test_folder/sub_eng_2.vtt')}}" srclang="en" label="English">
        <track kind="subtitles" src="{{asset('storage/hls/test_folder/sub_fre_5.vtt')}}" srclang="en" label="French">
        <track kind="subtitles" src="{{asset('storage/hls/test_folder/sub_ger_4.vtt')}}" srclang="en" label="German">
        <track kind="subtitles" src="{{asset('storage/hls/test_folder/sub_ita_7.vtt')}}" srclang="en" label="italian">
        <track kind="subtitles" src="{{asset('storage/hls/test_folder/sub_jpn_9.vtt')}}" srclang="en" label="Japanese" default>
    </video>
</div>

<script src="https://vjs.zencdn.net/8.10.0/video.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const player = videojs('hls-player', {
            autoplay: false,
            controls: true,
            responsive: true,
            fluid: true
        });

        player.on('error', function() {
            console.log('Video.js Error:', player.error());
        });
    });
</script>
</body>
</html>