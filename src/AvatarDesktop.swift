import AVFoundation
import AppKit

struct Settings {
    var size: CGFloat = 360
    var position: CGFloat = 0.5
    var margin: CGFloat = 24
    var draggable = true
    var alwaysOnTop = true
    var followTerminal = true
    var followEvery: Double = 1.0
    var hideWhenAway = true
    var terminalMinWidth: CGFloat = 400
    var terminalMinHeight: CGFloat = 300
    var waitForLoop = true
    var swapSeconds: Double = 0.08
    var preload: [String] = []

    init() {}

    init(json: String) {
        guard
            let data = json.data(using: .utf8),
            let given = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any]
        else {
            return
        }

        size = CGFloat((given["size"] as? NSNumber)?.doubleValue ?? Double(size))
        position = CGFloat((given["position"] as? NSNumber)?.doubleValue ?? Double(position))
        margin = CGFloat((given["margin"] as? NSNumber)?.doubleValue ?? Double(margin))
        draggable = (given["draggable"] as? Bool) ?? draggable
        alwaysOnTop = (given["always_on_top"] as? Bool) ?? alwaysOnTop
        followTerminal = (given["follow_terminal"] as? Bool) ?? followTerminal
        followEvery = (given["follow_every_seconds"] as? NSNumber)?.doubleValue ?? followEvery
        hideWhenAway = (given["hide_when_away"] as? Bool) ?? hideWhenAway
        terminalMinWidth = CGFloat((given["terminal_min_width"] as? NSNumber)?.doubleValue ?? Double(terminalMinWidth))
        terminalMinHeight = CGFloat((given["terminal_min_height"] as? NSNumber)?.doubleValue ?? Double(terminalMinHeight))
        waitForLoop = (given["wait_for_loop"] as? Bool) ?? waitForLoop
        swapSeconds = (given["swap_seconds"] as? NSNumber)?.doubleValue ?? swapSeconds
        preload = (given["preload"] as? [String]) ?? preload
    }
}

func report(_ message: [String: Any]) {
    guard
        let data = try? JSONSerialization.data(withJSONObject: message),
        let line = String(data: data, encoding: .utf8)
    else {
        return
    }

    FileHandle.standardOutput.write((line + "\n").data(using: .utf8)!)
}

final class AvatarWindow: NSWindow {
    var draggable = true

    var dropped: ((NSRect) -> Void)?

    override var canBecomeKey: Bool { true }

    override func mouseDown(with event: NSEvent) {
        guard draggable else { return }

        let start = NSEvent.mouseLocation
        let origin = frame.origin
        let home = (screen ?? NSScreen.main)?.frame ?? frame

        while let next = nextEvent(matching: [.leftMouseDragged, .leftMouseUp]) {
            if next.type == .leftMouseUp {
                break
            }

            let now = NSEvent.mouseLocation

            setFrame(
                NSRect(
                    x: min(max(origin.x + (now.x - start.x), home.minX), home.maxX - frame.width),
                    y: home.minY,
                    width: frame.width,
                    height: frame.height
                ),
                display: true
            )
        }

        if frame.origin != origin {
            dropped?(frame)
        }
    }
}

final class AvatarView: NSView {
    override func mouseDown(with event: NSEvent) {
        window?.mouseDown(with: event)
    }
}

/// A clip is a file on disk, or an https address to stream it from. Nothing else is a clip.
func clipURL(_ given: String) -> URL? {
    guard given.hasPrefix("https://") else {
        return URL(fileURLWithPath: given)
    }

    guard let url = URL(string: given), url.host != nil else { return nil }

    return url
}

extension URL {
    /// What a clip is known by: its path on disk, or its whole address.
    var clipKey: String { isFileURL ? path : absoluteString }
}

final class Delegate: NSObject, NSApplicationDelegate {
    private var window: AvatarWindow!
    private var players: [AVQueuePlayer] = []
    private var layers: [AVPlayerLayer] = []
    private var loopers: [AVPlayerLooper?] = [nil, nil]
    private var front = 0

    private var queued: URL?
    private var waitingOnLoop: Any?
    private var settings: Settings
    private let source: URL?
    private let loops: Bool

    init(settings: Settings, source: URL?, loops: Bool) {
        self.settings = settings
        self.source = source
        self.loops = loops
    }

    private func standingPlace() -> NSRect {
        let screen = standingOn
            ?? NSScreen.screens.first { NSMouseInRect(NSEvent.mouseLocation, $0.frame, false) }
            ?? NSScreen.main
            ?? NSScreen.screens.first

        let full = screen?.frame ?? .zero
        let room = max(0, full.width - settings.size - 2 * settings.margin)

        return NSRect(
            x: full.minX + settings.margin + room * min(1, max(0, settings.position)),
            y: full.minY,
            width: settings.size,
            height: settings.size
        )
    }

    private func wasDropped(at frame: NSRect) {
        let full = (window.screen ?? NSScreen.main)?.frame ?? .zero
        let room = max(1, full.width - settings.size - 2 * settings.margin)

        settings.position = min(1, max(0, (frame.minX - full.minX - settings.margin) / room))

        report(["moved": Double(settings.position)])
    }

    private var standingOn: NSScreen?

    private var homeWindow: CGWindowID?

    private func windowsFrontToBack() -> [(id: CGWindowID, pid: pid_t, frame: NSRect)] {
        let listed = CGWindowListCopyWindowInfo([.optionOnScreenOnly, .excludeDesktopElements], kCGNullWindowID)

        guard let windows = listed as? [[String: Any]] else { return [] }

        return windows.compactMap { window in
            guard
                let id = window[kCGWindowNumber as String] as? CGWindowID,
                let pid = window[kCGWindowOwnerPID as String] as? pid_t,
                (window[kCGWindowLayer as String] as? Int) == 0,
                let bounds = window[kCGWindowBounds as String] as? [String: CGFloat]
            else {
                return nil
            }

            let frame = NSRect(
                x: bounds["X"] ?? 0,
                y: bounds["Y"] ?? 0,
                width: bounds["Width"] ?? 0,
                height: bounds["Height"] ?? 0
            )

            return frame.width >= settings.terminalMinWidth && frame.height >= settings.terminalMinHeight ? (id, pid, frame) : nil
        }
    }

    private func ownerWindows() -> [(id: CGWindowID, frame: NSRect)] {
        guard let pid = owner?.processIdentifier else { return [] }

        return windowsFrontToBack().filter { $0.pid == pid }.map { ($0.id, $0.frame) }
    }

    private func screenOf(_ frame: NSRect) -> NSScreen? {
        let flipped = NSPoint(
            x: frame.midX,
            y: (NSScreen.screens.first?.frame.maxY ?? 0) - frame.midY
        )

        return NSScreen.screens.first { NSMouseInRect(flipped, $0.frame, false) }
    }

    private func screenShowingHome() -> NSScreen? {
        let windows = ownerWindows()

        if homeWindow == nil {
            homeWindow = windows.first?.id
        }

        guard let home = windows.first(where: { $0.id == homeWindow }) else {
            return nil
        }

        return screenOf(home.frame)
    }

    private func followHerWindow() {
        guard settings.followTerminal else { return }

        Timer.scheduledTimer(withTimeInterval: max(0.2, settings.followEvery), repeats: true) { [weak self] _ in
            guard let self, let window = self.window else { return }

            guard let now = self.screenShowingHome(), now !== self.standingOn else { return }

            self.standingOn = now
            window.setFrame(self.standingPlace(), display: true, animate: false)
        }
    }

    private var owner: NSRunningApplication?

    private func terminalThatStartedMe() -> NSRunningApplication? {
        var pid = getppid()

        for _ in 0 ..< 6 {
            if let app = NSRunningApplication(processIdentifier: pid), app.bundleIdentifier != nil {
                return app
            }

            var info = kinfo_proc()
            var size = MemoryLayout<kinfo_proc>.stride
            var mib: [Int32] = [CTL_KERN, KERN_PROC, KERN_PROC_PID, pid]

            guard sysctl(&mib, 4, &info, &size, nil, 0) == 0, info.kp_eproc.e_ppid > 1 else {
                break
            }

            pid = info.kp_eproc.e_ppid
        }

        return NSWorkspace.shared.runningApplications.first { $0.isActive && $0.bundleIdentifier != nil }
    }

    private var watchingFocus = false

    private var showing = true

    private var toldToHide = false

    private func hideWhenTheyLookAway() {
        guard settings.hideWhenAway, !watchingFocus else { return }

        watchingFocus = true

        owner = owner ?? terminalThatStartedMe()

        NSWorkspace.shared.notificationCenter.addObserver(
            forName: NSWorkspace.didActivateApplicationNotification,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            self?.showOnlyOverHome()
        }

        Timer.scheduledTimer(withTimeInterval: 0.25, repeats: true) { [weak self] _ in
            self?.showOnlyOverHome()
        }
    }

    private func homeIsInFront() -> Bool {
        guard let home = homeWindow, let window else { return true }

        let mine = NSScreen.screens.first { NSMouseInRect(NSPoint(x: window.frame.midX, y: window.frame.midY), $0.frame, false) }
        let me = ProcessInfo.processInfo.processIdentifier

        return windowsFrontToBack().first { $0.pid != me && screenOf($0.frame) === mine }?.id == home
    }

    private func showOnlyOverHome() {
        let wanted = !toldToHide && homeIsInFront()

        guard wanted != showing else { return }

        showing = wanted

        if wanted {
            window?.orderFrontRegardless()
        } else {
            window?.orderOut(nil)
        }
    }

    private var restingClip: URL?

    private var loaded: [String: AVPlayerItem] = [:]

    private func warmUp() {
        if !settings.preload.isEmpty {
            settings.preload.compactMap(clipURL).forEach { preload($0) }

            return
        }

        guard let first = source, first.isFileURL else { return }

        let folder = first.deletingLastPathComponent()

        guard let names = try? FileManager.default.contentsOfDirectory(atPath: folder.path) else {
            return
        }

        for name in names where name.hasSuffix(".mov") {
            preload(folder.appendingPathComponent(name))
        }
    }

    private func preload(_ url: URL) {
        let key = url.clipKey

        guard loaded[key] == nil else { return }

        let asset = AVURLAsset(url: url)
        let item = AVPlayerItem(asset: asset)

        asset.loadValuesAsynchronously(forKeys: ["tracks", "duration"])

        loaded[key] = item
    }

    private func play(_ url: URL, looping: Bool) {
        preload(url)

        let item = AVPlayerItem(asset: (loaded[url.clipKey]?.asset as? AVURLAsset) ?? AVURLAsset(url: url))

        let back = 1 - front
        let player = players[back]

        loopers[back] = nil
        player.removeAllItems()

        if looping {
            restingClip = url
            loopers[back] = AVPlayerLooper(player: player, templateItem: item)
        } else {
            player.insert(item, after: nil)

            NotificationCenter.default.addObserver(
                forName: .AVPlayerItemDidPlayToEndTime,
                object: item,
                queue: .main
            ) { [weak self] _ in
                report(["finished": url.clipKey])

                guard let self, let resting = self.restingClip else { return }

                self.play(resting, looping: true)
            }
        }

        player.play()

        streaming = !url.isFileURL

        if url.isFileURL {
            bringForward(back)
        } else {
            bringForwardOnceItCanPlay(item.asset, back)
        }
    }

    /// ★ A STREAMED CLIP IS SHOWN ONLY ONCE IT CAN PLAY. Until the address has answered with something playable the
    /// layer stays hidden, so an unreachable or wrong address leaves nothing on the screen: no frame, no error,
    /// and nothing waiting. The window is transparent, so a hidden layer is no window at all.
    private func bringForwardOnceItCanPlay(_ asset: AVAsset, _ next: Int) {
        asset.loadValuesAsynchronously(forKeys: ["playable"]) { [weak self] in
            DispatchQueue.main.async {
                guard
                    let self,
                    asset.statusOfValue(forKey: "playable", error: nil) == .loaded,
                    asset.isPlayable
                else {
                    return
                }

                self.bringForward(next)
            }
        }
    }

    private var streaming = false
    private var hiddenByTheNetwork = false
    private var stalledFor = 0

    /// ★ A STREAM THAT DROPS TAKES HER AWAY, QUIETLY. When a streamed clip fails, or stalls for two checks in a row,
    /// she is hidden and the player carries on; when the picture flows again she comes back. Clips on disk are
    /// never touched by this.
    private func watchTheStream() {
        Timer.scheduledTimer(withTimeInterval: 2, repeats: true) { [weak self] _ in
            guard let self, self.streaming else { return }

            let player = self.players[self.front]

            switch player.timeControlStatus {
            case .playing:
                self.stalledFor = 0

                if self.hiddenByTheNetwork {
                    self.hiddenByTheNetwork = false
                    self.layers[self.front].isHidden = false
                }
            case .waitingToPlayAtSpecifiedRate:
                self.stalledFor += 1

                if self.stalledFor >= 2, !self.hiddenByTheNetwork, !self.layers[self.front].isHidden {
                    self.hiddenByTheNetwork = true
                    self.layers[self.front].isHidden = true
                }
            default:
                break
            }
        }

        NotificationCenter.default.addObserver(
            forName: .AVPlayerItemFailedToPlayToEndTime,
            object: nil,
            queue: .main
        ) { [weak self] _ in
            guard let self, self.streaming else { return }

            self.hiddenByTheNetwork = true
            self.layers.forEach { $0.isHidden = true }
        }
    }

    private func bringForward(_ next: Int) {
        layers[next].isHidden = false

        let previous = front
        front = next

        guard previous != next else { return }

        DispatchQueue.main.asyncAfter(deadline: .now() + settings.swapSeconds) { [weak self] in
            guard let self, self.front != previous else { return }

            self.layers[previous].isHidden = true
            self.players[previous].pause()
        }
    }

    private var heardSoFar = ""

    private func heard(_ byte: UInt8) {
        guard byte != 0x0A else {
            let line = heardSoFar
            heardSoFar = ""

            DispatchQueue.main.async { self.wasTold(line) }

            return
        }

        heardSoFar.append(Character(UnicodeScalar(byte)))
    }

    private func playAtTheEndOfTheLoop(_ url: URL) {
        guard settings.waitForLoop, let item = players[front].currentItem, loopers[front] != nil else {
            play(url, looping: false)

            return
        }

        queued = url

        if let waiting = waitingOnLoop {
            NotificationCenter.default.removeObserver(waiting)
        }

        waitingOnLoop = NotificationCenter.default.addObserver(
            forName: .AVPlayerItemDidPlayToEndTime,
            object: item,
            queue: .main
        ) { [weak self] _ in
            guard let self, let next = self.queued else { return }

            self.queued = nil

            if let waiting = self.waitingOnLoop {
                NotificationCenter.default.removeObserver(waiting)
                self.waitingOnLoop = nil
            }

            self.play(next, looping: false)
        }
    }

    private func wasTold(_ line: String) {
        guard line.hasPrefix("{") else {
            let wantsLoop = line.hasSuffix(" --loop")

            playClip(wantsLoop ? String(line.dropLast(7)) : line, looping: wantsLoop)

            return
        }

        guard
            let data = line.data(using: .utf8),
            let message = (try? JSONSerialization.jsonObject(with: data)) as? [String: Any]
        else {
            return
        }

        if let paths = message["preload"] as? [String] {
            paths.compactMap(clipURL).forEach { preload($0) }
        }

        if message["hide"] as? Bool == true {
            toldToHide = true
            showing = false
            window?.orderOut(nil)
        }

        if message["show"] as? Bool == true {
            toldToHide = false
            showing = true
            window?.orderFrontRegardless()
        }

        if let path = message["play"] as? String {
            playClip(path, looping: (message["loop"] as? Bool) ?? false)
        }
    }

    private func playClip(_ path: String, looping: Bool) {
        guard let url = clipURL(path) else { return }

        if url.isFileURL, !FileManager.default.fileExists(atPath: path) { return }

        if looping {
            queued = nil

            play(url, looping: true)

            return
        }

        playAtTheEndOfTheLoop(url)
    }

    private func listenForClips() {
        let input = FileHandle.standardInput
        DispatchQueue.global(qos: .utility).async {
            var byte: UInt8 = 0

            while true {
                let got = read(input.fileDescriptor, &byte, 1)

                if got == 0 { DispatchQueue.main.async { exit(0) } ; return }
                if got < 0 && errno != EINTR { DispatchQueue.main.async { exit(0) } ; return }

                if got == 1 {
                    self.heard(byte)
                }
            }
        }
    }

    func applicationDidFinishLaunching(_: Notification) {
        owner = terminalThatStartedMe()

        standingOn = screenShowingHome()

        let frame = standingPlace()

        window = AvatarWindow(
            contentRect: frame,
            styleMask: [.borderless],
            backing: .buffered,
            defer: false
        )

        window.isOpaque = false
        window.backgroundColor = .clear
        window.hasShadow = false
        window.level = settings.alwaysOnTop ? .floating : .normal
        window.ignoresMouseEvents = false
        window.isMovableByWindowBackground = false
        window.draggable = settings.draggable
        window.dropped = { [weak self] frame in self?.wasDropped(at: frame) }

        window.collectionBehavior = [.moveToActiveSpace, .stationary]

        let bounds = NSRect(origin: .zero, size: frame.size)

        let host = AvatarView(frame: bounds)
        host.wantsLayer = true
        host.layer = CALayer()
        host.layer?.backgroundColor = NSColor.clear.cgColor

        for _ in 0 ..< 2 {
            let player = AVQueuePlayer()
            let layer = AVPlayerLayer(player: player)

            layer.frame = bounds
            layer.videoGravity = .resizeAspect
            layer.backgroundColor = NSColor.clear.cgColor
            layer.isHidden = true

            layer.pixelBufferAttributes = [
                kCVPixelBufferPixelFormatTypeKey as String: kCVPixelFormatType_32BGRA,
            ]

            players.append(player)
            layers.append(layer)
            host.layer?.addSublayer(layer)
        }

        window.contentView = host

        window.orderFrontRegardless()
        window.makeKeyAndOrderFront(nil)
        NSApp.activate(ignoringOtherApps: true)

        if let source {
            play(source, looping: loops)
        }

        hideWhenTheyLookAway()
        followHerWindow()
        watchTheStream()
        listenForClips()
        warmUp()
    }
}

let args = CommandLine.arguments

guard args.count > 1 else {
    print("usage: AvatarDesktop '<settings json>'  or  AvatarDesktop <file.mov|-> [size] [--loop]")
    exit(1)
}

let app = NSApplication.shared
app.setActivationPolicy(.accessory)

let delegate: Delegate

if args[1].hasPrefix("{") {
    delegate = Delegate(settings: Settings(json: args[1]), source: nil, loops: false)
} else {
    var settings = Settings()
    settings.size = args.count > 2 ? CGFloat(Double(args[2]) ?? 360) : 360
    settings.position = 0.5
    settings.margin = 0

    delegate = Delegate(
        settings: settings,
        source: args[1] == "-" ? nil : clipURL(args[1]),
        loops: args.contains("--loop")
    )
}

app.delegate = delegate
app.run()
