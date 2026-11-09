import importlib.util
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch

SPEC=importlib.util.spec_from_file_location('clock_monitor',Path(__file__).resolve().parents[1]/'scripts/clock_health_monitor.py')
monitor=importlib.util.module_from_spec(SPEC);SPEC.loader.exec_module(monitor)

class ClockMonitorTest(unittest.TestCase):
    def test_selected_peer_and_offset(self):
        p=monitor.peer_status(' remote refid st t when poll reach delay offset jitter\n*202.28.116.236 .PPS. 1 u 20 64 377 10.2 1.67 0.5')
        self.assertTrue(p['synchronized']);self.assertEqual(1.67,p['peer_offset_ms'])
    def test_no_selected_or_unreachable_peer(self):
        for row in ['+202.28.116.236 .PPS. 1 u 20 64 377 10.2 1.67 0.5','*202.28.116.236 .PPS. 16 u 20 64 377 10.2 1.67 0.5','*202.28.116.236 .PPS. 1 u 20 64 0 10.2 1.67 0.5','garbage']:
            self.assertFalse(monitor.peer_status(row)['synchronized'])
    def test_query_units_are_converted_to_milliseconds(self):
        p=monitor.independent_status('2026-11-09 19:14:38.620738 (+0700) +0.000408 +/- 0.013094 time.google.com 216.239.35.4 s1 no-leap')
        self.assertAlmostEqual(.408,p['independent_offset_ms']);self.assertAlmostEqual(13.094,p['independent_uncertainty_ms'])
    def test_healthy_and_worst_offset(self):
        s=monitor.classify(True,{'synchronized':True,'peer_offset_ms':1.6},{'independent_offset_ms':.4,'independent_uncertainty_ms':13})
        self.assertEqual('healthy',s['status']);self.assertEqual(1.6,s['offset_ms'])
    def test_service_failure_and_unsynchronized_are_critical(self):
        self.assertEqual('critical',monitor.classify(False,{'synchronized':True,'peer_offset_ms':1},{'independent_offset_ms':1})['status'])
        self.assertEqual('critical',monitor.classify(True,{'synchronized':False,'peer_offset_ms':None},{'independent_offset_ms':1})['status'])
    def test_independent_large_drift_catches_stale_local_sample(self):
        for offset in [5251390,-2500]:
            s=monitor.classify(True,{'synchronized':True,'peer_offset_ms':1},{'independent_offset_ms':offset,'independent_uncertainty_ms':15})
            self.assertEqual('critical',s['status']);self.assertIn('clock_offset',s['reason_codes'])
    def test_missing_independent_probe_is_warning_not_clock_change(self):
        s=monitor.classify(True,{'synchronized':True,'peer_offset_ms':1},{'independent_offset_ms':None})
        self.assertEqual('warning',s['status']);self.assertEqual(1,s['offset_ms'])
    def test_warning_offsets(self):
        self.assertEqual('warning',monitor.classify(True,{'synchronized':True,'peer_offset_ms':600},{'independent_offset_ms':10})['status'])
    def test_atomic_json_permissions_and_failure_preserves_old_status(self):
        with tempfile.TemporaryDirectory() as root:
            p=Path(root)/'status.json';fd=monitor.open_status_directory(root)
            try:
                monitor.write_status(fd,{'status':'healthy'},os.getgid())
                self.assertEqual({'status':'healthy'},json.loads(p.read_text()));self.assertEqual(0o640,stat.S_IMODE(p.stat().st_mode))
                with self.assertRaises(ValueError):monitor.write_status(fd,{'bad':float('nan')},os.getgid())
                self.assertEqual({'status':'healthy'},json.loads(p.read_text()));self.assertEqual(['status.json'],[f.name for f in Path(root).iterdir()])
            finally:os.close(fd)
    def test_missing_directory_is_not_created(self):
        with tempfile.TemporaryDirectory() as root:
            p=Path(root)/'missing'
            with self.assertRaises(FileNotFoundError):monitor.open_status_directory(p)
            self.assertFalse(p.exists())
    def test_symlink_directory_is_rejected_without_touching_target(self):
        with tempfile.TemporaryDirectory() as root:
            target=Path(root)/'target';target.mkdir(mode=0o750)
            p=Path(root)/'clock';p.symlink_to(target,target_is_directory=True)
            before=target.stat()
            with self.assertRaises(OSError):monitor.open_status_directory(p)
            after=target.stat();self.assertEqual(before.st_mode,after.st_mode);self.assertEqual(before.st_uid,after.st_uid);self.assertEqual([],list(target.iterdir()))
    def test_untrusted_owner_is_rejected(self):
        with tempfile.TemporaryDirectory() as root:
            with self.assertRaises(PermissionError):monitor.open_status_directory(root,expected_uid=os.geteuid()+1)
    def test_group_or_world_writable_directory_is_rejected(self):
        with tempfile.TemporaryDirectory() as root:
            for mode in [0o770,0o707,0o777]:
                os.chmod(root,mode)
                with self.assertRaises(PermissionError):monitor.open_status_directory(root)
            os.chmod(root,0o700)
    def test_parent_replacement_after_open_cannot_redirect_writes(self):
        with tempfile.TemporaryDirectory() as root:
            parent=Path(root);clock=parent/'clock';clock.mkdir(mode=0o750)
            victim=parent/'victim';victim.mkdir(mode=0o750);v=victim/'status.json';v.write_text('sensitive sentinel');os.chmod(v,0o600)
            before=(v.stat().st_mode,victim.stat().st_mode)
            fd=monitor.open_status_directory(clock)
            try:
                moved=parent/'moved';clock.rename(moved);clock.symlink_to(victim,target_is_directory=True)
                monitor.write_status(fd,{'status':'healthy'},os.getgid())
                self.assertEqual({'status':'healthy'},json.loads((moved/'status.json').read_text()))
                self.assertEqual('sensitive sentinel',v.read_text());self.assertEqual(before,(v.stat().st_mode,victim.stat().st_mode))
                with self.assertRaises(OSError):monitor.open_status_directory(clock)
            finally:os.close(fd)
    def test_lock_symlink_and_hardlink_are_rejected_without_target_write(self):
        with tempfile.TemporaryDirectory() as root:
            parent=Path(root);victim=parent/'sensitive';victim.write_text('sentinel');os.chmod(victim,0o600)
            fd=monitor.open_status_directory(root)
            try:
                link=parent/'monitor.lock';link.symlink_to(victim)
                with self.assertRaises(OSError):monitor.open_lock(fd)
                link.unlink();os.link(victim,link)
                with self.assertRaises(PermissionError):monitor.open_lock(fd)
                self.assertEqual('sentinel',victim.read_text());self.assertEqual(0o600,stat.S_IMODE(victim.stat().st_mode))
            finally:os.close(fd)
    def test_exclusive_temp_creation_does_not_follow_preexisting_symlink(self):
        with tempfile.TemporaryDirectory() as root:
            parent=Path(root);victim=parent/'sensitive';victim.write_text('sentinel');os.chmod(victim,0o600)
            malicious=parent/'.clock-collision.tmp';malicious.symlink_to(victim)
            fd=monitor.open_status_directory(root)
            try:
                with patch.object(monitor.secrets,'token_hex',return_value='collision'):
                    with self.assertRaises(FileExistsError):monitor.write_status(fd,{'status':'healthy'},os.getgid())
                self.assertEqual('sentinel',victim.read_text());self.assertTrue(malicious.is_symlink())
            finally:os.close(fd)
    def test_status_replacement_does_not_follow_destination_symlink(self):
        with tempfile.TemporaryDirectory() as root:
            parent=Path(root);victim=parent/'sensitive';victim.write_text('sentinel');os.chmod(victim,0o600)
            destination=parent/'status.json';destination.symlink_to(victim)
            fd=monitor.open_status_directory(root)
            try:
                monitor.write_status(fd,{'status':'healthy'},os.getgid())
                self.assertFalse(destination.is_symlink());self.assertEqual('sentinel',victim.read_text());self.assertEqual(0o600,stat.S_IMODE(victim.stat().st_mode))
            finally:os.close(fd)

if __name__=='__main__':unittest.main()
