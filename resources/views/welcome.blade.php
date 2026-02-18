{{--<!DOCTYPE html>--}}
{{--<html>--}}
{{--<head>--}}
{{--    <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet" />--}}
{{--    <title>HLS Transcoder</title>--}}
{{--</head>--}}
{{--<body >--}}

{{--<div style="height: 300vh; margin: 20px auto;">--}}
{{--    <video id="hls-player" class="video-js vjs-fluid vjs-big-play-centered" controls preload="auto">--}}
{{--        <source src="{{asset('storage/hls/fb1211f8-c461-4d42-a334-2080e770c3c8/master.m3u8')}}" type="application/x-mpegURL">--}}

{{--        <track kind="subtitles" src="{{asset('storage/hls/fb1211f8-c461-4d42-a334-2080e770c3c8/sub_eng_2.vtt')}}" srclang="en" label="English">--}}
{{--        <track kind="subtitles" src="{{asset('storage/hls/fb1211f8-c461-4d42-a334-2080e770c3c8/sub_fre_5.vtt')}}" srclang="en" label="French">--}}
{{--        <track kind="subtitles" src="{{asset('storage/hls/fb1211f8-c461-4d42-a334-2080e770c3c8/sub_ger_4.vtt')}}" srclang="en" label="German">--}}
{{--        <track kind="subtitles" src="{{asset('storage/hls/fb1211f8-c461-4d42-a334-2080e770c3c8/sub_ita_7.vtt')}}" srclang="en" label="italian">--}}
{{--        <track kind="subtitles" src="{{asset('storage/hls/fb1211f8-c461-4d42-a334-2080e770c3c8/sub_jpn_9.vtt')}}" srclang="en" label="Japanese" default>--}}
{{--    </video>--}}
{{--</div>--}}

{{--<script src="https://vjs.zencdn.net/8.10.0/video.min.js"></script>--}}
{{--<script>--}}
{{--    document.addEventListener('DOMContentLoaded', function() {--}}
{{--        const player = videojs('hls-player', {--}}
{{--            autoplay: false,--}}
{{--            controls: true,--}}
{{--            responsive: true,--}}
{{--            fluid: true--}}
{{--        });--}}

{{--        player.on('error', function() {--}}
{{--            console.log('Video.js Error:', player.error());--}}
{{--        });--}}
{{--    });--}}
{{--</script>--}}
{{--</body>--}}
{{--</html>--}}

<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="https://vjs.zencdn.net/8.10.0/video-js.css" rel="stylesheet" />
    <title>HLS Transcoder</title>
</head>
<body>

<div style="width: 800px; margin: 20px auto; height: 300vh;">
    <video
            id="hls-player"
            class="video-js vjs-fluid vjs-big-play-centered"
            controls
            preload="auto"
            crossorigin="anonymous">
        <source src="{{ asset('storage/hls/fb1211f8-c461-4d42-a334-2080e770c3c8/master.m3u8') }}" type="application/x-mpegURL">
    </video>
</div>

<script src="https://vjs.zencdn.net/8.10.0/video.min.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const player = videojs('hls-player', {
            html5: {
                vhs: { overrideNative: true },
                nativeVideoTracks: false,
                nativeAudioTracks: false,
                nativeTextTracks: false
            }
        });

        player.ready(function() {
            // Force the text track display to stay visible
            const textTrackDisplay = player.getChild('textTrackDisplay');
            if (textTrackDisplay) {
                textTrackDisplay.show();
            }
        });

        player.on('loadedmetadata', function() {
            const tracks = player.textTracks();

            for (let i = 0; i < tracks.length; i++) {
                // Only touch tracks that are 'subtitles' or 'captions'
                if (tracks[i].kind === 'subtitles' || tracks[i].kind === 'captions') {
                    if (tracks[i].language === 'eng') {
                        tracks[i].mode = 'showing';
                    } else {
                        tracks[i].mode = 'hidden';
                    }
                } else {
                    // This is likely the 'metadata' track that was causing the error
                    tracks[i].mode = 'disabled';
                }
            }
        });
    });
</script>
</body>
</html>