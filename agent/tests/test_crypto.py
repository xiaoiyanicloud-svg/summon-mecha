"""测试 JCS (RFC 8785) 规范化编码与 RSA-SHA256 签名"""

import json
import unittest
from zhjj_agent.crypto import jcs_canonicalize, canonical_v1, canonical_v2, verify_signature, sign_data


class TestCrypto(unittest.TestCase):
    def test_jcs_canonicalize_order(self):
        d1 = {"b": 2, "a": 1, "c": {"y": 20, "x": 10}}
        d2 = {"c": {"x": 10, "y": 20}, "a": 1, "b": 2}
        
        bytes1 = jcs_canonicalize(d1)
        bytes2 = jcs_canonicalize(d2)
        self.assertEqual(bytes1, bytes2)
        self.assertEqual(bytes1, b'{"a":1,"b":2,"c":{"x":10,"y":20}}')

    def test_jcs_types(self):
        obj = {
            "str": "hello",
            "int": 42,
            "null": None,
            "bool_true": True,
            "bool_false": False,
            "list": [3, 1, 2]
        }
        canonical = jcs_canonicalize(obj)
        self.assertEqual(
            canonical,
            b'{"bool_false":false,"bool_true":true,"int":42,"list":[3,1,2],"null":null,"str":"hello"}'
        )

    def test_canonical_v2_prefix(self):
        signed = {"task_id": "T1001", "did": "did_test_123"}
        v2_bytes = canonical_v2(signed)
        self.assertTrue(v2_bytes.startswith(b"summon-mecha/agent-task/v2\n"))
        self.assertEqual(v2_bytes, b'summon-mecha/agent-task/v2\n{"did":"did_test_123","task_id":"T1001"}')

    def test_rsa_signature_and_verify(self):
        from cryptography.hazmat.primitives.asymmetric import rsa
        from cryptography.hazmat.primitives import serialization

        key = rsa.generate_private_key(public_exponent=65537, key_size=2048)
        priv_pem = key.private_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PrivateFormat.PKCS8,
            encryption_algorithm=serialization.NoEncryption()
        ).decode("ascii")
        pub_pem = key.public_key().public_bytes(
            encoding=serialization.Encoding.PEM,
            format=serialization.PublicFormat.SubjectPublicKeyInfo
        ).decode("ascii")

        signed = {"task_id": "T2002", "did": "did_abc", "operation": 1}
        msg_bytes = canonical_v2(signed)

        sig_b64 = sign_data(msg_bytes, priv_pem)
        self.assertTrue(verify_signature(msg_bytes, sig_b64, pub_pem))

        tampered_bytes = canonical_v2({"task_id": "T2002", "did": "did_abc", "operation": 2})
        self.assertFalse(verify_signature(tampered_bytes, sig_b64, pub_pem))
        self.assertFalse(verify_signature(msg_bytes, "invalid_sig", pub_pem))


if __name__ == "__main__":
    unittest.main()
